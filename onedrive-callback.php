<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// 自动获取重定向地址
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$redirect_uri = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];


// 默认内置参数（可留空）
$default_config = [
    'client_id'     => '', // 客户端id
    'client_secret' => ''//客户端密码
];

// 合并请求参数
$request_params = array_merge(
    $_GET, 
    json_decode(file_get_contents('php://input'), true) ?? []
);

// 动态获取客户端凭证
$client_id = $request_params['client_id'] ?? $default_config['client_id'];
$client_secret = $request_params['client_secret'] ?? $default_config['client_secret'];

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

    // 生成带凭证的state参数
    $state = base64_encode(json_encode([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'timestamp' => time()
    ]));

    $authUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query([
        'client_id' => $client_id,
        'response_type' => 'code',
        'redirect_uri' => $redirect_uri,
        'scope' => 'Files.ReadWrite.All offline_access',
        'state' => $state,
        'response_mode' => 'query'
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

    // 解析state参数
    $state = json_decode(base64_decode($query['state']), true);
    if (empty($state['client_id']) || empty($state['client_secret'])) {
        http_response_code(400);
        die(json_encode(['error' => '无效的state参数']));
    }

    // 请求访问令牌
    $tokenData = [
        'client_id' => $state['client_id'],
        'client_secret' => $state['client_secret'],
        'code' => $query['code'],
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code'
    ];

    $response = postRequest('https://login.microsoftonline.com/common/oauth2/v2.0/token', $tokenData);

    if (isset($response['refresh_token'])) {
        echo json_encode([
            'refresh_token' => $response['refresh_token'],
            'access_token' => $response['access_token'],
            'expires_in' => $response['expires_in']
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => '令牌获取失败', 'details' => $response]);
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
        'client_id' => $data['client_id'],
        'client_secret' => $data['client_secret'],
        'refresh_token' => $data['refresh_token'],
        'grant_type' => 'refresh_token'
    ];

    $response = postRequest('https://login.microsoftonline.com/common/oauth2/v2.0/token', $tokenData);

    if (isset($response['access_token'])) {
        echo json_encode([
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $data['refresh_token'],
            'expires_in' => $response['expires_in']
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => '令牌刷新失败', 'details' => $response]);
    }
}

// 公共请求方法
function postRequest($url, $data) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);
    $response = curl_exec($ch);
    return json_decode($response, true);
}