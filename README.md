# **使用方法**

注意：PHP需要开启curl扩展

### **阿里云盘：**
在代码中填入对应的开放平台应用的APP ID和APP Secret，drive_id可空

redirect_uri地址是：你的域名+文件名

例如：https://yourdomain.com/alipan-callback.php

![image](/img/alipan.png)
---
### 使用refresh_token更新接口
json格式post请求

| 参数名| -|
|--------|--------|
| client_id| 必填|
| client_secret|  必填|
| drive_id| 可选|
| refresh_token| 必填|
---
### **OneDrive：**
在代码中填入对应的开放平台应用的客户端id和客户端密码

redirect_uri地址是：你的域名+文件名

例如：https://yourdomain.com/onedrive-callback.php

![image](/img/onedrive.png)
---
### 使用refresh_token更新接口
json格式post请求

| 参数名| -|
|--------|--------|
| client_id| 必填|
| client_secret|  必填|
| refresh_token| 必填|
