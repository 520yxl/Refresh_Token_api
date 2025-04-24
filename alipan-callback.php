<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// 自动获取重定向地址
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$redirect_uri = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

// 默认内置参数（阿里云控制台获取）
$default_config = [
    'client_id'     => '',//开放平台的APP ID
    'client_secret' => '',//开放平台的App Secret
    'drive_id'      => '' // 可选参数，默认留空
];

// 合并请求参数
$request_params = array_merge(
    $_GET,
    json_decode(file_get_contents('php://input'), true) ?? []
);

// 动态获取客户端凭证
$client_id = $request_params['client_id'] ?? $default_config['client_id'];
$client_secret = $request_params['client_secret'] ?? $default_config['client_secret'];
$drive_id = $request_params['drive_id'] ?? $default_config['drive_id'] ?? '';

// 主流程控制
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['code'])) {
        handleAuthorizationCallback($_GET, $redirect_uri);
    } else {
        initAuthorizationFlow($client_id, $client_secret, $redirect_uri);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleTokenRefresh(file_get_contents('php://input'));
}

// 初始化授权请求
function initAuthorizationFlow($client_id, $client_secret, $redirect_uri) {
    if (empty($client_id) || empty($client_secret)) {
        http_response_code(400);
        die(json_encode(['error' => '缺少客户端凭证参数']));
    }

    // 生成带参数的state
    $state = base64_encode(json_encode([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'timestamp' => time(),
        'sign' => hash_hmac('sha256', $client_id.$client_secret, 'SECRET_SALT')
    ]));

    // 新版授权地址
    $authUrl = 'https://openapi.alipan.com/oauth/authorize?'.http_build_query([
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'user:base,file:all:read,file:all:write',
        'state' => $state
    ]);

    header('Location: '.$authUrl);
    exit;
}

// 处理回调
function handleAuthorizationCallback($query, $redirect_uri) {
    if (!isset($query['state']) || !isset($query['code'])) {
        http_response_code(400);
        die(json_encode(['error' => '无效的授权响应']));
    }

    // 验证state签名
    $state = json_decode(base64_decode($query['state']), true);
    $expectedSign = hash_hmac('sha256', $state['client_id'].$state['client_secret'], 'SECRET_SALT');
    if (!hash_equals($expectedSign, $state['sign'])) {
        http_response_code(403);
        die(json_encode(['error' => '参数签名验证失败']));
    }

    // 请求访问令牌
    $tokenData = [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'client_id' => $state['client_id'],
        'client_secret' => $state['client_secret'],
        'redirect_uri' => $redirect_uri
    ];

    $response = postRequest('https://openapi.alipan.com/oauth/access_token', $tokenData);

    if (isset($response['refresh_token'])) {
        echo json_encode([
            'refresh_token' => $response['refresh_token'],
            'access_token' => $response['access_token'],
            'expires_in' => $response['expires_in'],
            'drive_id' => $response['drive_id'] ?? ''
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => '令牌获取失败', 'code' => $response['code'] ?? '', 'message' => $response['message'] ?? '']);
    }
}

// 处理令牌刷新
function handleTokenRefresh($input) {
    $data = json_decode($input, true);
    if (empty($data['refresh_token']) || empty($data['client_id']) || empty($data['client_secret'])) {
        http_response_code(400);
        die(json_encode(['error' => '缺少必要参数']));
    }

    $tokenData = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $data['refresh_token'],
        'client_id' => $data['client_id'],
        'client_secret' => $data['client_secret']
    ];

    // 可选传递drive_id
    if (!empty($data['drive_id'])) {
        $tokenData['drive_id'] = $data['drive_id'];
    }

    $response = postRequest('https://openapi.alipan.com/oauth/access_token', $tokenData);

    if (isset($response['access_token'])) {
        echo json_encode([
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $data['refresh_token'],
            'expires_in' => $response['expires_in'],
            'drive_id' => $response['drive_id'] ?? ''
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => '令牌刷新失败', 'code' => $response['code'] ?? '', 'message' => $response['message'] ?? '']);
    }
}

// 公共请求方法
function postRequest($url, $data) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data), // 阿里云要求表单格式
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: AliyunDrive-API-Client'
        ]
    ]);
    $response = curl_exec($ch);
    return json_decode($response, true);
}