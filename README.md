# SSPanel-to-V2board

# 如果本项目，确实帮助到了您👍，烦请点一个Star✨

SSPanel迁移至wyx2685分支的V2board，目前仅在bob分支的SSPanel上测试，不清楚其他分支的SSPanel是否适用(大概率可以)  
>兼容SSPanel的md5登陆密码加密并自动升级password_hash请自行修改V2board

app/Utils/Helper.php
```php
    public static function multiPasswordVerify($algo, $salt, $password, $hash)
    {
        switch($algo) {
            case 'md5': return md5($password) === $hash;
            case 'sha256': return hash('sha256', $password) === $hash;
            case 'md5salt': return md5($password . $salt) === $hash;
            default:
                // 优先使用当前算法验证
                if (is_string($hash) && $hash !== '' && password_verify($password, $hash)) {
                    return true;
                }
                // 兼容老版：当未记录算法或算法字段为空时，尝试常见旧算法
                // 1) md5(password)
                if (strlen($hash) === 32 && ctype_xdigit($hash) && md5($password) === strtolower($hash)) {
                    return true;
                }
                // 2) md5(password . salt)
                if (!empty($salt) && strlen($hash) === 32 && ctype_xdigit($hash) && md5($password . $salt) === strtolower($hash)) {
                    return true;
                }
                // 3) sha256(password)
                if (strlen($hash) === 64 && ctype_xdigit($hash) && hash('sha256', $password) === strtolower($hash)) {
                    return true;
                }
                return false;
        }
    }
```

app/Http/Controllers/V1/Passport/AuthController.php
```php
    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ((int)config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)config('v2board.password_limit_count', 5)) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('Incorrect email or password'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)config('v2board.password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int)$passwordErrorCount + 1,
                    60 * (int)config('v2board.password_limit_expire', 60)
                );
            }
            abort(500, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }

        // 登录成功后：若检测为旧版密码算法，自动升级为当前 password_hash
        $algo = $user->password_algo;
        $hash = $user->password;
        $isLegacyByAlgo = in_array($algo, ['md5', 'md5salt', 'sha256'], true);
        $isHex32 = is_string($hash) && strlen($hash) === 32 && ctype_xdigit($hash);
        $isHex64 = is_string($hash) && strlen($hash) === 64 && ctype_xdigit($hash);
        $isLegacyByGuess = (empty($algo) || $algo === null) && ($isHex32 || $isHex64);
        if ($isLegacyByAlgo || $isLegacyByGuess) {
            $user->password = password_hash($password, PASSWORD_DEFAULT);
            $user->password_algo = null;
            $user->password_salt = null;
            // 静默升级失败不应影响登录
            try { $user->save(); } catch (\Throwable $e) { /* ignore */ }
        }

        $authService = new AuthService($user);
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }
```

>兼容SSPanel的/link/{token}订阅链接请自行修改V2board

app/Utils/Helper.php
```php
    public static function getSubscribeUrl($token)
    {
        $path = config('v2board.subscribe_path', '/api/v1/client/subscribe');
        if (empty($path)) {
            $path = '/api/v1/client/subscribe';
        } 
        $subscribeUrls = explode(',', config('v2board.subscribe_url'));
        $subscribeUrl = $subscribeUrls[rand(0, count($subscribeUrls) - 1)];
        
        // 确保 path 末尾有 /，然后添加 token
        $path = rtrim($path, '/') . '/' . $token;
        if ($subscribeUrl) {
            // 确保 subscribeUrl 末尾有 /
            $subscribeUrl = rtrim($subscribeUrl, '/') . '/';
            return $subscribeUrl . ltrim($path, '/');
        }
        return url($path);
    }
```

app/Http/Routes/V1/ClientRoute.php  
```php
    public function map(Registrar $router)
    {

        
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client'
        ], function ($router) {
            // Client
            if (empty(config('v2board.subscribe_path'))) {
                $router->get('/subscribe', 'V1\\Client\\ClientController@subscribe');
            }
            // App
            $router->get('/app/getConfig', 'V1\\Client\\AppController@getConfig');
            $router->get('/app/getVersion', 'V1\\Client\\AppController@getVersion');
        });
    }
```

sspanel_bought_to_plan_migration.php 通过最后一次购买记录确定套餐信息  
>需要自行在V2board，创建好套餐和用户组，并替换迁移文件中的套餐映射信息

sspanel_to_v2board_migration.php 用于迁移用户表  
>在迁移前V2board仅存在一个用户即管理员用户

流量计算过程：
```php
// 1. 确定transfer_enable
        if ($autoResetBandwidth > 0) {
            // auto_reset_bandwidth不为0 -> transfer_enable(十进制GB转二进制字节)
            $transferEnable = $this->gbToBytes($autoResetBandwidth);
        } else {
            // auto_reset_bandwidth为0 -> transfer_enable(ssp) -> transfer_enable(v2_user)
            $transferEnable = $sspTransferEnable;
        }
        // 2. 计算已使用流量: transfer_enable(ssp) - u(ssp) - d(ssp)
        $usedTraffic = max(0, $sspTransferEnable - $sspU - $sspD);
        
        // 3. 计算剩余可用流量: transfer_enable(v2_user) - 已使用流量
        $remainingTraffic = max(0, $transferEnable - $usedTraffic);
        
        // 4. 按比例分配u和d
        $totalUsed = $sspU + $sspD;
        if ($totalUsed > 0) {
            // 按比例计算
            $uRatio = $sspU / $totalUsed;
            $dRatio = $sspD / $totalUsed;
            
            $u = intval($remainingTraffic * $uRatio);
            $d = intval($remainingTraffic * $dRatio);
        } else {
            // 如果没有使用流量，都设为0
            $u = 0;
            $d = 0;
        }
```
