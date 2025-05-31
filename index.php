<?php
// 初始化设置
session_start();
date_default_timezone_set('Asia/Shanghai');
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');

// 定义常量
define('USER_FILE', 'data/users.json');
define('FILE_FILE', 'data/files.json');
define('UPLOAD_DIR', 'uploads/');
define('CHUNK_SIZE', 990 * 1024); // 990KB

// 初始化目录和文件
if (!file_exists('data')) mkdir('data');
if (!file_exists(UPLOAD_DIR)) mkdir(UPLOAD_DIR);
if (!file_exists(USER_FILE)) file_put_contents(USER_FILE, '[]');
if (!file_exists(FILE_FILE)) file_put_contents(FILE_FILE, '[]');

// 加载用户数据
function loadUsers() {
    $users = json_decode(file_get_contents(USER_FILE), true) ?: [];
    return $users;
}

// 保存用户数据
function saveUsers($users) {
    file_put_contents(USER_FILE, json_encode($users, JSON_PRETTY_PRINT));
}

// 加载文件数据
function loadFiles() {
    $files = json_decode(file_get_contents(FILE_FILE), true) ?: [];
    return $files;
}

// 保存文件数据
function saveFiles($files) {
    file_put_contents(FILE_FILE, json_encode($files, JSON_PRETTY_PRINT));
}

// 生成用户ID
function generateUserId($users) {
    return count($users) > 0 ? max(array_column($users, 'id')) + 1 : 1;
}

// 生成文件ID
function generateFileId($files) {
    return count($files) > 0 ? max(array_column($files, 'id')) + 1 : 1;
}

// 密码加密
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

// 验证密码
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// 处理用户动作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'register':
            handleRegister();
            break;
        case 'login':
            handleLogin();
            break;
        case 'reset_password':
            handleResetPassword();
            break;
        case 'sign':
            handleSign();
            break;
        case 'transfer':
            handleTransfer();
            break;
        case 'upload_chunk':
            handleUploadChunk();
            break;
        case 'complete_upload':
            handleCompleteUpload();
            break;
        case 'delete_file':
            handleDeleteFile();
            break;
        case 'collect':
            handleCollect();
            break;
        case 'like':
            handleLike();
            break;
        case 'buy':
            handleBuy();
            break;
        case 'generate_download':
            handleGenerateDownload();
            break;
    }
}

// 注册处理
function handleRegister() {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm = trim($_POST['confirm'] ?? '');
    
    if (empty($username) || empty($password)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '用户名和密码不能为空'];
        return;
    }
    
    if ($password !== $confirm) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '两次输入的密码不一致'];
        return;
    }
    
    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['username'] === $username) {
            $_SESSION['message'] = ['type' => 'error', 'text' => '用户名已存在'];
            return;
        }
    }
    
    $newUser = [
        'id' => generateUserId($users),
        'username' => $username,
        'password' => hashPassword($password),
        'gold' => 0,
        'level' => 0,
        'sign_days' => 0,
        'last_sign' => '',
        'collections' => [],
        'likes' => [],
        'purchases' => []
    ];
    
    $users[] = $newUser;
    saveUsers($users);
    
    $_SESSION['user_id'] = $newUser['id'];
    $_SESSION['message'] = ['type' => 'success', 'text' => '注册成功!'];
}

// 登录处理
function handleLogin() {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($username) || empty($password)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '用户名和密码不能为空'];
        return;
    }
    
    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['username'] === $username && verifyPassword($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['message'] = ['type' => 'success', 'text' => '登录成功！'];
            return;
        }
    }
    
    $_SESSION['message'] = ['type' => 'error', 'text' => '用户名或密码错误'];
}

// 重置密码处理
function handleResetPassword() {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '请先登录'];
        return;
    }
    
    $old_password = trim($_POST['old_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm = trim($_POST['confirm'] ?? '');
    
    if (empty($old_password) || empty($new_password)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '密码不能为空'];
        return;
    }
    
    if ($new_password !== $confirm) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '两次输入的新密码不一致'];
        return;
    }
    
    $users = loadUsers();
    foreach ($users as &$user) {
        if ($user['id'] == $_SESSION['user_id']) {
            if (!verifyPassword($old_password, $user['password'])) {
                $_SESSION['message'] = ['type' => 'error', 'text' => '旧密码错误'];
                return;
            }
            
            $user['password'] = hashPassword($new_password);
            saveUsers($users);
            $_SESSION['message'] = ['type' => 'success', 'text' => '密码修改成功'];
            return;
        }
    }
    
    $_SESSION['message'] = ['type' => 'error', 'text' => '用户不存在'];
}

// 签到处理
function handleSign() {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '请先登录'];
        return;
    }
    
    $users = loadUsers();
    foreach ($users as &$user) {
        if ($user['id'] == $_SESSION['user_id']) {
            $today = date('Y-m-d');
            
            // 检查今天是否已签到
            if ($user['last_sign'] === $today) {
                $_SESSION['message'] = ['type' => 'error', 'text' => '今天已经签到过了'];
                return;
            }
            
            // 计算连续签到
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            if ($user['last_sign'] === $yesterday) {
                $user['sign_days']++;
            } else {
                $user['sign_days'] = 1;
            }
            
            // 计算金币奖励 (10-30)
            $gold = rand(10, 30);
            $user['gold'] += $gold;
            
            // 更新等级
            if ($user['sign_days'] >= 4) {
                $user['level'] = 3;
            } elseif ($user['sign_days'] >= 2) {
                $user['level'] = 2;
            } else {
                $user['level'] = 1;
            }
            
            $user['last_sign'] = $today;
            saveUsers($users);
            
            $_SESSION['message'] = ['type' => 'success', 'text' => "签到成功！获得{$gold}金币，连续签到{$user['sign_days']}天"];
            return;
        }
    }
    
    $_SESSION['message'] = ['type' => 'error', 'text' => '用户不存在'];
}

// 转账处理 - 修改为使用用户ID
function handleTransfer() {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '请先登录'];
        return;
    }
    
    $to_user_id = intval($_POST['to_user_id'] ?? 0);
    $amount = intval($_POST['amount'] ?? 0);
    
    if ($to_user_id <= 0 || $amount <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '请输入有效的接收用户ID和金额'];
        return;
    }
    
    // 不能转账给自己
    if ($to_user_id == $_SESSION['user_id']) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '不能转账给自己'];
        return;
    }
    
    $users = loadUsers();
    $found = false;
    $sender = null;
    
    // 查找接收者
    foreach ($users as &$user) {
        if ($user['id'] == $to_user_id) {
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '接收用户不存在'];
        return;
    }
    
    // 查找发送者
    foreach ($users as &$user) {
        if ($user['id'] == $_SESSION['user_id']) {
            if ($user['gold'] < $amount) {
                $_SESSION['message'] = ['type' => 'error', 'text' => '金币不足'];
                return;
            }
            
            $user['gold'] -= $amount;
            $sender = $user;
            break;
        }
    }
    
    // 更新接收者
    foreach ($users as &$user) {
        if ($user['id'] == $to_user_id) {
            $user['gold'] += $amount;
            saveUsers($users);
            
            $_SESSION['message'] = ['type' => 'success', 'text' => "成功转账{$amount}金币给用户ID:{$to_user_id}"];
            return;
        }
    }
}

// 分块上传处理
function handleUploadChunk() {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    
    $fileId = $_POST['file_id'] ?? '';
    $chunkIndex = $_POST['chunk_index'] ?? 0;
    $totalChunks = $_POST['total_chunks'] ?? 0;
    
    if (empty($fileId) || empty($_FILES['chunk'])) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        exit;
    }
    
    $tempDir = UPLOAD_DIR . "temp_{$fileId}/";
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    $chunkFile = $tempDir . $chunkIndex;
    
    if (move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
        echo json_encode(['success' => true, 'chunk' => $chunkIndex]);
    } else {
        echo json_encode(['success' => false, 'message' => '分块上传失败']);
    }
    exit;
}

// 完成上传处理
function handleCompleteUpload() {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '请先登录'];
        return;
    }
    
    $fileId = $_POST['file_id'] ?? '';
    $fileName = $_POST['file_name'] ?? '';
    $fileDesc = $_POST['file_desc'] ?? '';
    $price = intval($_POST['price'] ?? 0);
    $totalChunks = intval($_POST['total_chunks'] ?? 0);
    
    if (empty($fileId) || empty($fileName) || $totalChunks <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '参数错误'];
        return;
    }
    
    $tempDir = UPLOAD_DIR . "temp_{$fileId}/";
    $finalDir = UPLOAD_DIR . "{$fileId}/";
    $finalPath = $finalDir . $fileName;
    
    if (!file_exists($tempDir)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '找不到临时文件'];
        return;
    }
    
    // 创建最终目录
    if (!file_exists($finalDir)) {
        mkdir($finalDir, 0777, true);
    }
    
    // 合并文件
    $final = fopen($finalPath, 'wb');
    if (!$final) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '无法创建文件'];
        return;
    }
    
    $fileSize = 0;
    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkFile = $tempDir . $i;
        if (!file_exists($chunkFile)) {
            fclose($final);
            $_SESSION['message'] = ['type' => 'error', 'text' => "分块{$i}缺失"];
            return;
        }
        
        $chunk = file_get_contents($chunkFile);
        fwrite($final, $chunk);
        $fileSize += filesize($chunkFile);
        unlink($chunkFile);
    }
    fclose($final);
    rmdir($tempDir);
    
    // 添加到文件列表
    $files = loadFiles();
    $file = [
        'id' => generateFileId($files),
        'file_id' => $fileId,
        'owner_id' => $_SESSION['user_id'],
        'name' => $fileName,
        'path' => $finalPath,
        'size' => $fileSize,
        'desc' => $fileDesc,
        'price' => $price,
        'upload_time' => date('Y-m-d H:i:s'),
        'likes' => 0,
        'collections' => 0,
        'purchases' => 0,
        'downloads' => 0 // 新增下载次数统计
    ];
    
    $files[] = $file;
    saveFiles($files);
    
    $_SESSION['message'] = ['type' => 'success', 'text' => '文件上传成功！'];
}

// 删除文件处理
function handleDeleteFile() {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '请先登录'];
        return;
    }
    
    $fileId = $_POST['file_id'] ?? 0;
    if ($fileId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => '参数错误'];
        return;
    }
    
    $files = loadFiles();
    $found = false;
    
    foreach ($files as $key => $file) {
        if ($file['id'] == $fileId && $file['owner_id'] == $_SESSION['user_id']) {
            // 删除文件
            if (file_exists($file['path'])) {
                unlink($file['path']);
            }
            
            // 删除目录
            $fileDir = dirname($file['path']);
            if (is_dir($fileDir)) {
                rmdir($fileDir);
            }
            
            unset($files[$key]);
            $found = true;
            break;
        }
    }
    
    if ($found) {
        saveFiles(array_values($files));
        $_SESSION['message'] = ['type' => 'success', 'text' => '文件删除成功'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => '文件不存在或无权删除'];
    }
}

// 收藏处理
function handleCollect() {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    
    $fileId = intval($_POST['file_id'] ?? 0);
    if ($fileId <= 0) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        exit;
    }
    
    $files = loadFiles();
    $users = loadUsers();
    $updated = false;
    
    foreach ($files as &$file) {
        if ($file['id'] == $fileId) {
            foreach ($users as &$user) {
                if ($user['id'] == $_SESSION['user_id']) {
                    if (in_array($fileId, $user['collections'])) {
                        // 取消收藏
                        $key = array_search($fileId, $user['collections']);
                        unset($user['collections'][$key]);
                        $file['collections']--;
                        $action = 'remove';
                    } else {
                        // 添加收藏
                        $user['collections'][] = $fileId;
                        $file['collections']++;
                        $action = 'add';
                    }
                    
                    $user['collections'] = array_values($user['collections']);
                    saveUsers($users);
                    saveFiles($files);
                    echo json_encode(['success' => true, 'action' => $action]);
                    exit;
                }
            }
        }
    }
    
    echo json_encode(['success' => false, 'message' => '文件不存在']);
    exit;
}

// 点赞处理
function handleLike() {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    
    $fileId = intval($_POST['file_id'] ?? 0);
    if ($fileId <= 0) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        exit;
    }
    
    $files = loadFiles();
    $users = loadUsers();
    $updated = false;
    
    foreach ($files as &$file) {
        if ($file['id'] == $fileId) {
            // 检查是否自己的文件
            if ($file['owner_id'] == $_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => '不能给自己的文件点赞']);
                exit;
            }
            
            foreach ($users as &$user) {
                if ($user['id'] == $_SESSION['user_id']) {
                    if (in_array($fileId, $user['likes'])) {
                        // 取消点赞
                        $key = array_search($fileId, $user['likes']);
                        unset($user['likes'][$key]);
                        $file['likes']--;
                        $action = 'remove';
                    } else {
                        // 添加点赞
                        $user['likes'][] = $fileId;
                        $file['likes']++;
                        $action = 'add';
                    }
                    
                    $user['likes'] = array_values($user['likes']);
                    saveUsers($users);
                    saveFiles($files);
                    echo json_encode(['success' => true, 'action' => $action]);
                    exit;
                }
            }
        }
    }
    
    echo json_encode(['success' => false, 'message' => '文件不存在']);
    exit;
}

// 购买处理
function handleBuy() {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    
    $fileId = intval($_POST['file_id'] ?? 0);
    if ($fileId <= 0) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        exit;
    }
    
    $files = loadFiles();
    $users = loadUsers();
    
    $file = null;
    foreach ($files as $f) {
        if ($f['id'] == $fileId) {
            $file = $f;
            break;
        }
    }
    
    if (!$file) {
        echo json_encode(['success' => false, 'message' => '文件不存在']);
        exit;
    }
    
    // 检查是否自己的文件
    if ($file['owner_id'] == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => '不能购买自己的文件']);
        exit;
    }
    
    // 检查是否已购买
    $buyer = null;
    foreach ($users as &$user) {
        if ($user['id'] == $_SESSION['user_id']) {
            if (isset($user['purchases'][$fileId])) {
                echo json_encode(['success' => false, 'message' => '您已经购买过此文件']);
                exit;
            }
            
            // 检查金币
            if ($user['gold'] < $file['price']) {
                echo json_encode(['success' => false, 'message' => '金币不足']);
                exit;
            }
            
            $user['gold'] -= $file['price'];
            $user['purchases'][$fileId] = date('Y-m-d H:i:s');
            $buyer = $user;
            break;
        }
    }
    
    // 给卖家金币
    foreach ($users as &$user) {
        if ($user['id'] == $file['owner_id']) {
            $user['gold'] += $file['price'];
            break;
        }
    }
    
    // 更新文件购买次数
    foreach ($files as &$f) {
        if ($f['id'] == $fileId) {
            $f['purchases']++;
            break;
        }
    }
    
    saveUsers($users);
    saveFiles($files);
    
    echo json_encode(['success' => true, 'gold' => $buyer['gold']]);
    exit;
}

// 生成下载链接处理
function handleGenerateDownload() {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }
    
    $fileId = intval($_POST['file_id'] ?? 0);
    if ($fileId <= 0) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        exit;
    }
    
    $users = loadUsers();
    $files = loadFiles();
    
    $hasPurchased = false;
    foreach ($users as $user) {
        if ($user['id'] == $_SESSION['user_id']) {
            $hasPurchased = isset($user['purchases'][$fileId]);
            break;
        }
    }
    
    if (!$hasPurchased) {
        echo json_encode(['success' => false, 'message' => '您尚未购买此文件']);
        exit;
    }
    
    $file = null;
    foreach ($files as $f) {
        if ($f['id'] == $fileId) {
            $file = $f;
            break;
        }
    }
    
    if (!$file || !file_exists($file['path'])) {
        echo json_encode(['success' => false, 'message' => '文件不存在']);
        exit;
    }
    
    // 生成临时下载令牌（有效时间10分钟）
    $token = md5($fileId . $_SESSION['user_id'] . time());
    $_SESSION['download_token'] = $token;
    $_SESSION['download_file'] = $file['path'];
    $_SESSION['download_time'] = time();
    
    // 更新下载次数
    foreach ($files as &$f) {
        if ($f['id'] == $fileId) {
            $f['downloads']++;
            saveFiles($files);
            break;
        }
    }
    
    $downloadUrl = "index.php?action=download&token={$token}";
    
    echo json_encode(['success' => true, 'url' => $downloadUrl]);
    exit;
}

// 下载处理
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $token = $_GET['token'] ?? '';
    
    if (empty($token)) {
        die('无效的下载请求');
    }
    
    if (!isset($_SESSION['download_token']) || $_SESSION['download_token'] !== $token) {
        die('下载令牌无效');
    }
    
    // 检查令牌有效期（10分钟）
    if (time() - $_SESSION['download_time'] > 600) {
        unset($_SESSION['download_token'], $_SESSION['download_file'], $_SESSION['download_time']);
        die('下载链接已过期');
    }
    
    $filePath = $_SESSION['download_file'];
    
    if (!file_exists($filePath)) {
        die('文件不存在');
    }
    
    // 准备下载
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    
    // 清空缓冲区并发送文件
    ob_clean();
    flush();
    readfile($filePath);
    
    // 清理会话变量
    unset($_SESSION['download_token'], $_SESSION['download_file'], $_SESSION['download_time']);
    exit;
}

// 获取当前用户信息
function getCurrentUser() {
    if (isset($_SESSION['user_id'])) {
        $users = loadUsers();
        foreach ($users as $user) {
            if ($user['id'] == $_SESSION['user_id']) {
                return $user;
            }
        }
    }
    return null;
}

// 获取文件列表
function getFiles() {
    $files = loadFiles();
    // 按上传时间倒序排列
    usort($files, function($a, $b) {
        return strtotime($b['upload_time']) - strtotime($a['upload_time']);
    });
    return $files;
}

// 获取文件信息
function getFileById($id) {
    $files = loadFiles();
    foreach ($files as $file) {
        if ($file['id'] == $id) {
            return $file;
        }
    }
    return null;
}

// 检查用户是否购买过文件
function hasPurchased($userId, $fileId) {
    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['id'] == $userId) {
            return isset($user['purchases'][$fileId]);
        }
    }
    return false;
}

// 检查用户是否收藏
function hasCollected($userId, $fileId) {
    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['id'] == $userId) {
            return in_array($fileId, $user['collections']);
        }
    }
    return false;
}

// 检查用户是否点赞
function hasLiked($userId, $fileId) {
    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['id'] == $userId) {
            return in_array($fileId, $user['likes']);
        }
    }
    return false;
}

// 格式化文件大小
function formatSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// 显示消息
function displayMessage() {
    if (isset($_SESSION['message'])) {
        $msg = $_SESSION['message'];
        $class = $msg['type'] === 'success' ? 'success' : 'error';
        echo "<div class='message {$class}'>{$msg['text']}</div>";
        unset($_SESSION['message']);
    }
}

// 生成随机文件ID（用于分块上传）
function generateRandomFileId() {
    return md5(uniqid() . time());
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件交易平台</title>
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3a56d9;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --border: #dee2e6;
            --card-shadow: 0 4px 8px rgba(0,0,0,0.05);
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        body {
            background-color: #f5f7ff;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: var(--primary);
            color: white;
            padding: 20px 0;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
            position: relative;
            z-index: 2;
            flex-wrap: wrap;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .logo svg {
            width: 28px;
            height: 28px;
            fill: white;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .user-stats {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        
        .user-stats span {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            background: white;
            color: var(--primary);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            font-size: 15px;
            margin-bottom: 10px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            background: #f0f4ff;
        }
        
        .btn svg {
            width: 18px;
            height: 18px;
        }
        
        .btn-primary {
            background: var(--info);
            color: white;
        }
        
        .btn-primary:hover {
            background: #3a86ff;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #e11573;
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }
        
        @media (min-width: 992px) {
            .main-content {
                grid-template-columns: 280px 1fr;
            }
        }
        
        .panel {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
        }
        
        .panel:hover {
            box-shadow: 0 6px 12px rgba(0,0,0,0.08);
        }
        
        .panel-title {
            font-size: 22px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-gray);
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .panel-title svg {
            width: 24px;
            height: 24px;
            fill: var(--primary);
        }
        
        .menu {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 14px 16px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 16px;
            color: var(--dark);
            gap: 12px;
        }
        
        .menu-item:hover {
            background: #f0f7ff;
            color: var(--primary);
        }
        
        .menu-item.active {
            background: #e6f2ff;
            color: var(--primary);
            font-weight: 600;
        }
        
        .menu-item svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }
        
       .file-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 1;
            min-width: 0;
            padding-right: 10px;
        }

        .file-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
        }
        
        .file-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .file-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }
        
        .file-header {
            background: var(--primary);
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-width: 0;
            overflow: hidden;
        }
        
        .file-id {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            flex-shrink: 0;
        }
        
        .file-body {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        
        .file-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .file-desc {
            color: var(--gray);
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.5;
            flex-grow: 1;
        }
        
        .file-meta {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--gray);
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .file-meta div {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .file-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--danger);
            text-align: right;
            margin-bottom: 15px;
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
        }
        
        .action-btn {
            flex: 1;
            text-align: center;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .action-btn svg {
            width: 16px;
            height: 16px;
        }
        
        .like-btn {
            background: #fff0f6;
            color: #f759ab;
            border: 1px solid #ffd6e7;
        }
        
        .like-btn:hover {
            background: #ffe5f0;
        }
        
        .like-btn.liked {
            background: #f759ab;
            color: white;
        }
        
        .collect-btn {
            background: #f0f5ff;
            color: #1d39c4;
            border: 1px solid #adc6ff;
        }
        
        .collect-btn:hover {
            background: #e6eeff;
        }
        
        .collect-btn.collected {
            background: #1d39c4;
            color: white;
        }
        
        .buy-btn {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .buy-btn:hover {
            background: #edffe4;
        }
        
        .download-btn {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
        }
        
        .download-btn:hover {
            background: #d4eeff;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }
        
        input, textarea, select {
            width: 100%;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 16px;
            transition: var(--transition);
            background: white;
        }
        
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .message svg {
            width: 20px;
            height: 20px;
        }
        
        .message.success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: #52c41a;
        }
        
        .message.error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: #f5222d;
        }
        
        .upload-container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
        }
        
        .progress-container {
            margin-top: 20px;
            display: none;
        }
        
        .progress-bar {
            height: 12px;
            background: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .progress {
            height: 100%;
            background: var(--primary);
            border-radius: 10px;
            width: 0%;
            transition: width 0.3s ease;
        }
        
        .progress-text {
            text-align: center;
            font-size: 14px;
            color: var(--gray);
        }
        
        .search-bar {
            display: flex;
            margin-bottom: 25px;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .search-bar input {
            flex: 1;
            min-width: 200px;
        }
        
        footer {
            text-align: center;
            margin-top: 50px;
            padding: 20px;
            color: var(--gray);
            font-size: 14px;
            border-top: 1px solid var(--border);
        }
        
        .hidden {
            display: none;
        }
        
        .section {
            display: none;
        }
        
        .section.active {
            display: block;
            animation: fadeIn 0.4s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .transfer-form {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .transfer-form input {
            flex: 1;
            min-width: 200px;
        }
        
        .transfer-form button {
            width: 100px;
        }
        
        .icon {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
            grid-column: 1 / -1;
        }
        
        .empty-state svg {
            width: 60px;
            height: 60px;
            fill: var(--light-gray);
            margin-bottom: 20px;
        }
        
        .user-id-hint {
            display: block;
            margin-top: 5px;
            color: var(--gray);
            font-size: 13px;
        }
        
        .user-id-display {
            font-weight: bold;
            color: var(--danger);
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .user-info {
                width: 100%;
                justify-content: flex-start;
            }
            
            .file-list {
                grid-template-columns: 1fr;
            }
            
            .file-actions {
                flex-wrap: wrap;
            }
            
            .action-btn {
                min-width: 80px;
            }
            
            .search-bar button {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .user-stats {
                width: 100%;
                justify-content: center;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <div class="logo">
                    <svg viewBox="0 0 24 24">
                        <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                    </svg>
                    文件交易平台
                </div>
                <?php $user = getCurrentUser(); ?>
                <?php if ($user): ?>
                    <div class="user-info">
                        <div class="user-stats">
                            <span>
                                <svg class="icon" viewBox="0 0 24 24">
                                    <path d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z"/>
                                </svg>
                                ID: <?php echo $user['id']; ?>
                            </span>
                            <span>
                                <svg class="icon" viewBox="0 0 24 24">
                                    <path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/>
                                </svg>
                                LV<?php echo $user['level']; ?>
                            </span>
                            <span>
                                <svg class="icon" viewBox="0 0 24 24">
                                    <path d="M12,2A10,10 0 0,1 22,12A10,10 0 0,1 12,22A10,10 0 0,1 2,12A10,10 0 0,1 12,2M12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20A8,8 0 0,0 20,12A8,8 0 0,0 12,4M12,6A6,6 0 0,1 18,12A6,6 0 0,1 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6M12,8A4,4 0 0,0 8,12A4,4 0 0,0 12,16A4,4 0 0,0 16,12A4,4 0 0,0 12,8Z"/>
                                </svg>
                                金币: <?php echo $user['gold']; ?>
                            </span>
                        </div>
                        <a href="#" onclick="showSection('profile')" class="btn">
                            <svg viewBox="0 0 24 24">
                                <path d="M12,19.2C9.5,19.2 7.29,17.92 6,16C6.03,14 10,12.9 12,12.9C14,12.9 17.97,14 18,16C16.71,17.92 14.5,19.2 12,19.2M12,5A3,3 0 0,1 15,8A3,3 0 0,1 12,11A3,3 0 0,1 9,8A3,3 0 0,1 12,5M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12C22,6.47 17.5,2 12,2Z"/>
                            </svg>
                            <?php echo htmlspecialchars($user['username']); ?>
                        </a>
                        <a href="#" onclick="logout()" class="btn btn-primary">
                            <svg viewBox="0 0 24 24">
                                <path d="M16,17V14H9V10H16V7L21,12L16,17M14,2A2,2 0 0,1 16,4V6H14V4H5V20H14V18H16V20A2,2 0 0,1 14,22H5A2,2 0 0,1 3,20V4A2,2 0 0,1 5,2H14Z"/>
                            </svg>
                            退出
                        </a>
                    </div>
                <?php else: ?>
                    <div class="user-info">
                        <a href="#" onclick="showSection('login')" class="btn">
                            <svg viewBox="0 0 24 24">
                                <path d="M10,17V14H3V10H10V7L15,12L10,17M10,2H19A2,2 0 0,1 21,4V20A2,2 0 0,1 19,22H10A2,2 0 0,1 8,20V18H10V20H19V4H10V6H8V4A2,2 0 0,1 10,2Z"/>
                            </svg>
                            登录
                        </a>
                        <a href="#" onclick="showSection('register')" class="btn btn-primary">
                            <svg viewBox="0 0 24 24">
                                <path d="M15,14C12.33,14 7,15.33 7,18V20H23V18C23,15.33 17.67,14 15,14M6,10V7H4V10H1V12H4V15H6V12H9V10M15,12A4,4 0 0,0 19,8A4,4 0 0,0 15,4A4,4 0 0,0 11,8A4,4 0 0,0 15,12Z"/>
                            </svg>
                            注册
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </header>
        
        <?php displayMessage(); ?>
        
        <div class="main-content">
            <?php if ($user): ?>
            <div class="panel">
                <h2 class="panel-title">
                    <svg viewBox="0 0 24 24">
                        <path d="M3,6H21V8H3V6M3,11H21V13H3V11M3,16H21V18H3V16Z"/>
                    </svg>
                    功能菜单
                </h2>
                <div class="menu">
                    <div class="menu-item active" onclick="showSection('files')">
                        <svg viewBox="0 0 24 24">
                            <path d="M9,3V18H12V3H9M12,5L16,18L19,17L15,4L12,5M5,5V18H8V5H5M3,19V21H21V19H3Z"/>
                        </svg>
                        文件市场
                    </div>
                    <div class="menu-item" onclick="showSection('upload')">
                        <svg viewBox="0 0 24 24">
                            <path d="M9,16V10H5L12,3L19,10H15V16H9M5,20V18H19V20H5Z"/>
                        </svg>
                        上传文件
                    </div>
                    <div class="menu-item" onclick="showSection('profile')">
                        <svg viewBox="0 0 24 24">
                            <path d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z"/>
                        </svg>
                        个人中心
                    </div>
                    <div class="menu-item" onclick="showSection('transfer')">
                        <svg viewBox="0 0 24 24">
                            <path d="M20,8H14V10H20V8M20,12H14V14H20V12M20,16H14V18H20V16M4,18H12V16H4V18M4,12H12V10H4V12M4,6V8H12V6H4M4,18V20H12V18H4M14,4H4C2.89,4 2,4.89 2,6V20A2,2 0 0,0 4,22H20A2,2 0 0,0 22,20V8A2,2 0 0,0 20,6H14V4Z"/>
                        </svg>
                        金币转账
                    </div>
                    <div class="menu-item" onclick="sign()">
                        <svg viewBox="0 0 24 24">
                            <path d="M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z"/>
                        </svg>
                        每日签到
                    </div>
                    <div class="menu-item" onclick="showSection('myfiles')">
                        <svg viewBox="0 0 24 24">
                            <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                        </svg>
                        我的文件
                    </div>
                    <div class="menu-item" onclick="showSection('collections')">
                        <svg viewBox="0 0 24 24">
                            <path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35Z"/>
                        </svg>
                        我的收藏
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="panel">
                <!-- 文件市场 -->
                <div id="files-section" class="section active">
                    <h2 class="panel-title">
                        <svg viewBox="0 0 24 24">
                            <path d="M9,3V18H12V3H9M12,5L16,18L19,17L15,4L12,5M5,5V18H8V5H5M3,19V21H21V19H3Z"/>
                        </svg>
                        文件市场
                    </h2>
                    <div class="search-bar">
                        <input type="text" id="search-input" placeholder="搜索文件ID、名称或描述...">
                        <button class="btn" onclick="searchFiles()">
                            <svg viewBox="0 0 24 24">
                                <path d="M9.5,3A6.5,6.5 0 0,1 16,9.5C16,11.11 15.41,12.59 14.44,13.73L14.71,14H15.5L20.5,19L19,20.5L14,15.5V14.71L13.73,14.44C12.59,15.41 11.11,16 9.5,16A6.5,6.5 0 0,1 3,9.5A6.5,6.5 0 0,1 9.5,3M9.5,5C7,5 5,7 5,9.5C5,12 7,14 9.5,14C12,14 14,12 14,9.5C14,7 12,5 9.5,5Z"/>
                            </svg>
                            搜索
                        </button>
                    </div>
                    <div class="file-list" id="file-list">
                        <?php 
                            $files = getFiles();
                            if (count($files) > 0): 
                                foreach ($files as $file): 
                                    $owner = null; 
                                    $users = loadUsers();
                                    foreach ($users as $u) {
                                        if ($u['id'] == $file['owner_id']) {
                                            $owner = $u;
                                            break;
                                        }
                                    }
                        ?>
                            <div class="file-card" data-id="<?php echo $file['id']; ?>">
                                <div class="file-header">
                                    <div class="file-name"><?php echo htmlspecialchars($file['name']); ?></div>
                                    <div class="file-id">ID: <?php echo $file['id']; ?></div>
                                </div>
                                <div class="file-body">
                                    <div class="file-desc"><?php echo htmlspecialchars($file['desc']); ?></div>
                                    <div class="file-meta">
                                        <div>
                                            <svg class="icon" viewBox="0 0 24 24">
                                                <path d="M4,4A2,2 0 0,0 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8A2,2 0 0,0 20,6H12L10,4H4Z"/>
                                            </svg>
                                            大小: <?php echo formatSize($file['size']); ?>
                                        </div>
                                        <div>
                                            <svg class="icon" viewBox="0 0 24 24">
                                                <path d="M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z"/>
                                            </svg>
                                            上传: <?php echo date('m-d H:i', strtotime($file['upload_time'])); ?>
                                        </div>
                                    </div>
                                    <div class="file-meta">
                                        <div>
                                            <svg class="icon" viewBox="0 0 24 24">
                                                <path d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z"/>
                                            </svg>
                                            所有者: <?php echo $owner ? htmlspecialchars($owner['username']) : '未知'; ?>
                                        </div>
                                        <div>
                                            <svg class="icon" viewBox="0 0 24 24">
                                                <path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/>
                                            </svg>
                                            下载: <?php echo $file['downloads']; ?>次
                                        </div>
                                    </div>
                                    <div class="file-price">
                                        <?php if ($file['price'] > 0): ?>
                                            <?php echo $file['price']; ?> 金币
                                        <?php else: ?>
                                            免费
                                        <?php endif; ?>
                                    </div>
                                    <div class="file-actions">
                                        <div class="action-btn like-btn <?php echo $user && hasLiked($user['id'], $file['id']) ? 'liked' : ''; ?>" 
                                            onclick="likeFile(<?php echo $file['id']; ?>)">
                                            <svg viewBox="0 0 24 24">
                                                <path d="M23,10C23,8.89 22.1,8 21,8H14.68L15.64,3.43C15.66,3.33 15.67,3.22 15.67,3.11C15.67,2.7 15.5,2.32 15.23,2.05L14.17,1L7.59,7.58C7.22,7.95 7,8.45 7,9V19A2,2 0 0,0 9,21H18C18.83,21 19.54,20.5 19.84,19.78L22.86,12.73C22.95,12.5 23,12.26 23,12V10M1,21H5V9H1V21Z"/>
                                            </svg>
                                            <?php echo $file['likes']; ?>
                                        </div>
                                        <div class="action-btn collect-btn <?php echo $user && hasCollected($user['id'], $file['id']) ? 'collected' : ''; ?>" 
                                            onclick="collectFile(<?php echo $file['id']; ?>)">
                                            <svg viewBox="0 0 24 24">
                                                <path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35Z"/>
                                            </svg>
                                            <?php echo $file['collections']; ?>
                                        </div>
                                        <?php if ($user && $user['id'] != $file['owner_id']): ?>
                                            <?php if (hasPurchased($user['id'], $file['id'])): ?>
                                                <div class="action-btn download-btn" onclick="downloadFile(<?php echo $file['id']; ?>)">
                                                    <svg viewBox="0 0 24 24">
                                                        <path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/>
                                                    </svg>
                                                    下载
                                                </div>
                                            <?php else: ?>
                                                <div class="action-btn buy-btn" onclick="buyFile(<?php echo $file['id']; ?>)">
                                                    <svg viewBox="0 0 24 24">
                                                        <path d="M17,18A2,2 0 0,1 19,20A2,2 0 0,1 17,22C15.89,22 15,21.1 15,20C15,18.89 15.89,18 17,18M1,2H4.27L5.21,4H20A1,1 0 0,1 21,5C21,5.17 20.95,5.34 20.88,5.5L17.3,11.97C16.96,12.58 16.3,13 15.55,13H8.1L7.2,14.63L7.17,14.75A0.25,0.25 0 0,0 7.42,15H19V17H7C5.89,17 5,16.1 5,15C5,14.65 5.09,14.32 5.24,14.04L6.6,11.59L3,4H1V2M7,18A2,2 0 0,1 9,20A2,2 0 0,1 7,22C5.89,22 5,21.1 5,20C5,18.89 5.89,18 7,18M16,11L18.78,6H6.14L8.5,11H16Z"/>
                                                    </svg>
                                                    购买
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24">
                                    <path d="M14,2H6C4.89,2 4,2.89 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20M13,10V11H14V10H13M13,12V13H14V12H13M13,14V15H15V14H13M13,16V17H16V16H13Z"/>
                                </svg>
                                <h3>暂无文件</h3>
                                <p>当前没有任何文件可供交易</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 上传文件 -->
                <div id="upload-section" class="section hidden">
                    <h2 class="panel-title">
                        <svg viewBox="0 0 24 24">
                            <path d="M9,16V10H5L12,3L19,10H15V16H9M5,20V18H19V20H5Z"/>
                        </svg>
                        上传文件
                    </h2>
                    <form id="upload-form" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="file">选择文件</label>
                            <input type="file" id="file" required>
                        </div>
                        <div class="form-group">
                            <label for="file-desc">文件描述</label>
                            <textarea id="file-desc" rows="3" placeholder="请输入文件描述..." required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="file-price">出售价格（金币）</label>
                            <input type="number" id="file-price" min="0" value="0" required>
                        </div>
                        <input type="hidden" id="file-id" value="<?php echo generateRandomFileId(); ?>">
                        <button type="button" class="btn btn-primary" onclick="startUpload()">
                            <svg viewBox="0 0 24 24">
                                <path d="M9,16V10H5L12,3L19,10H15V16H9M5,20V18H19V20H5Z"/>
                            </svg>
                            开始上传
                        </button>
                    </form>
                    <div class="progress-container" id="progress-container">
                        <div class="progress-bar">
                            <div class="progress" id="progress-bar"></div>
                        </div>
                        <div class="progress-text" id="progress-text">0%</div>
                    </div>
                </div>
                
                <!-- 个人中心 -->
                <div id="profile-section" class="section hidden">
                    <h2 class="panel-title">
                        <svg viewBox="0 0 24 24">
                            <path d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z"/>
                        </svg>
                        个人中心
                    </h2>
                    <?php if ($user): ?>
                        <div class="form-group">
                            <label>
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;vertical-align:middle;margin-right:8px;fill:#4361ee;">
                                    <path d="M10,17V14H3V10H10V7L15,12L10,17M10,2H19A2,2 0 0,1 21,4V20A2,2 0 0,1 19,22H10A2,2 0 0,1 8,20V18H10V20H19V4H10V6H8V4A2,2 0 0,1 10,2Z"/>
                                </svg>
                                用户名
                            </label>
                            <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;vertical-align:middle;margin-right:8px;fill:#4361ee;">
                                    <path d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z"/>
                                </svg>
                                用户ID
                            </label>
                            <input type="text" value="<?php echo $user['id']; ?>" readonly class="user-id-display">
                            <span class="user-id-hint">转账时请提供此ID给他人</span>
                        </div>
                        <div class="form-group">
                            <label>
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;vertical-align:middle;margin-right:8px;fill:#4361ee;">
                                    <path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/>
                                </svg>
                                等级
                            </label>
                            <input type="text" value="LV<?php echo $user['level']; ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;vertical-align:middle;margin-right:8px;fill:#4361ee;">
                                    <path d="M12,2A10,10 0 0,1 22,12A10,10 0 0,1 12,22A10,10 0 0,1 2,12A10,10 0 0,1 12,2M12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20A8,8 0 0,0 20,12A8,8 0 0,0 12,4M12,6A6,6 0 0,1 18,12A6,6 0 0,1 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6M12,8A4,4 0 0,0 8,12A4,4 0 0,0 12,16A4,4 0 0,0 16,12A4,4 0 0,0 12,8Z"/>
                                </svg>
                                金币
                            </label>
                            <input type="text" value="<?php echo $user['gold']; ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;vertical-align:middle;margin-right:8px;fill:#4361ee;">
                                    <path d="M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z"/>
                                </svg>
                                连续签到
                            </label>
                            <input type="text" value="<?php echo $user['sign_days']; ?>天" readonly>
                        </div>
                        <h3 style="margin: 20px 0 15px; display: flex; align-items: center; gap: 8px;">
                            <svg class="icon" viewBox="0 0 24 24" style="fill:#4361ee;">
                                <path d="M12,15.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.21,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.67 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z"/>
                            </svg>
                            修改密码
                        </h3>
                        <form method="post">
                            <input type="hidden" name="action" value="reset_password">
                            <div class="form-group">
                                <label for="old_password">旧密码</label>
                                <input type="password" name="old_password" id="old_password" required>
                            </div>
                            <div class="form-group">
                                <label for="new_password">新密码</label>
                                <input type="password" name="new_password" id="new_password" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm">确认新密码</label>
                                <input type="password" name="confirm" id="confirm" required>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <svg viewBox="0 0 24 24">
                                    <path d="M21,7L9,19L3.5,13.5L4.91,12.09L9,16.17L19.59,5.59L21,7Z"/>
                                </svg>
                                修改密码
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                
                <!-- 金币转账 -->
                <div id="transfer-section" class="section hidden">
                    <h2 class="panel-title">
                        <svg viewBox="0 0 24 24">
                            <path d="M20,8H14V10H20V8M20,12H14V14H20V12M20,16H14V18H20V16M4,18H12V16H4V18M4,12H12V10H4V12M4,6V8H12V6H4M4,18V20H12V18H4M14,4H4C2.89,4 2,4.89 2,6V20A2,2 0 0,0 4,22H20A2,2 0 0,0 22,20V8A2,2 0 0,0 20,6H14V4Z"/>
                        </svg>
                        金币转账
                    </h2>
                    <form method="post">
                        <input type="hidden" name="action" value="transfer">
                        <div class="form-group">
                            <label for="to_user_id">
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;vertical-align:middle;margin-right:8px;fill:#4361ee;">
                                    <path d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z"/>
                                </svg>
                                收款用户ID
                            </label>
                            <input type="number" name="to_user_id" id="to_user_id" min="1" required placeholder="请输入用户ID">
                            <span class="user-id-hint">用户ID可在个人资料页面查看</span>
                        </div>
                        <div class="form-group">
                            <label for="amount">
                                <svg viewBox="0 0 24 24" style="width:20px;height:20px;vertical-align:middle;margin-right:8px;fill:#4361ee;">
                                    <path d="M15,14C12.33,14 7,15.33 7,18V20H23V18C23,15.33 17.67,14 15,14M6,10V7H4V10H1V12H4V15H6V12H9V10M15,12A4,4 0 0,0 19,8A4,4 0 0,0 15,4A4,4 0 0,0 11,8A4,4 0 0,0 15,12Z"/>
                                </svg>
                                转账金额
                            </label>
                            <input type="number" name="amount" id="amount" min="1" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <svg viewBox="0 0 24 24">
                                <path d="M3,13H21V11H3M3,6V8H21V6M3,18H21V16H3V18Z"/>
                            </svg>
                            确认转账
                        </button>
                    </form>
                </div>
                
                <!-- 我的文件 -->
                <div id="myfiles-section" class="section hidden">
                    <h2 class="panel-title">
                        <svg viewBox="0 0 24 24">
                            <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                        </svg>
                        我的文件
                    </h2>
                    <div class="file-list" id="my-file-list">
                        <?php 
                            $files = loadFiles();
                            $myFiles = array_filter($files, function($file) use ($user) {
                                return $file['owner_id'] == $user['id'];
                            });
                            
                            if (count($myFiles) > 0): 
                                foreach ($myFiles as $file): 
                        ?>
                            <div class="file-card" data-id="<?php echo $file['id']; ?>">
                                <div class="file-header">
                                    <div class="file-name"><?php echo htmlspecialchars($file['name']); ?></div>
                                    <div class="file-id">ID: <?php echo $file['id']; ?></div>
                                </div>
                                <div class="file-body">
                                    <div class="file-desc"><?php echo htmlspecialchars($file['desc']); ?></div>
                                    <div class="file-meta">
                                        <div>
                                            <svg class="icon" viewBox="0 0 24 24">
                                                <path d="M4,4A2,2 0 0,0 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8A2,2 0 0,0 20,6H12L10,4H4Z"/>
                                            </svg>
                                            大小: <?php echo formatSize($file['size']); ?>
                                        </div>
                                        <div>
                                            <svg class="icon" viewBox="0 0 24 24">
                                                <path d="M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z"/>
                                            </svg>
                                            上传: <?php echo date('m-d H:i', strtotime($file['upload_time'])); ?>
                                        </div>
                                    </div>
                                    <div class="file-meta">
                                        <div>
                                            <svg class="icon" viewBox="0 0 24 24">
                                                <path d="M23,10C23,8.89 22.1,8 21,8H14.68L15.64,3.43C15.66,3.33 15.67,3.22 15.67,3.11C15.67,2.7 15.5,2.32 15.23,2.05L14.17,1L7.59,7.58C7.22,7.95 7,8.45 7,9V19A2,2 0 0,0 9,21H18C18.83,21 19.54,20.5 19.84,19.78L22.86,12.73C22.95,12.5 23,12.26 23,12V10M1,21H5V9H1V21Z"/>
                                            </svg>
                                            点赞: <?php echo $file['likes']; ?>
                                        </div>
                                        <div>
                                            <svg class="icon" viewBox="0 0 24 24">
                                                <path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35Z"/>
                                            </svg>
                                            收藏: <?php echo $file['collections']; ?>
                                        </div>
                                        <div>
                                            <svg class="icon" viewBox="0 0 24 24">
                                                <path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/>
                                            </svg>
                                            下载: <?php echo $file['downloads']; ?>次
                                        </div>
                                    </div>
                                    <div class="file-price">
                                        <?php if ($file['price'] > 0): ?>
                                            <?php echo $file['price']; ?> 金币
                                        <?php else: ?>
                                            免费
                                        <?php endif; ?>
                                    </div>
                                    <div class="file-actions">
                                        <div class="action-btn" style="background: #f0f5ff; color: #1d39c4; border: 1px solid #adc6ff;">
                                            <svg viewBox="0 0 24 24">
                                                <path d="M12,15.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.21,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.67 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z"/>
                                            </svg>
                                            收入: <?php echo $file['price'] * $file['purchases']; ?>金币
                                        </div>
                                        <div class="action-btn download-btn" onclick="downloadFile(<?php echo $file['id']; ?>)">
                                            <svg viewBox="0 0 24 24">
                                                <path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/>
                                            </svg>
                                            下载
                                        </div>
                                        <div class="action-btn btn-danger" 
                                            onclick="deleteFile(<?php echo $file['id']; ?>)">
                                            <svg viewBox="0 0 24 24">
                                                <path d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z"/>
                                            </svg>
                                            删除
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24">
                                    <path d="M14,2H6C4.89,2 4,2.89 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20M11,18H8V16H11V18M11,15H8V13H11V15M11,12H8V10H11V12Z"/>
                                </svg>
                                <h3>暂无文件</h3>
                                <p>您还没有上传任何文件</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 我的收藏 -->
                <div id="collections-section" class="section hidden">
                    <h2 class="panel-title">
                        <svg viewBox="0 0 24 24">
                            <path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35Z"/>
                        </svg>
                        我的收藏
                    </h2>
                    <div class="file-list" id="collection-list">
                        <?php if ($user && count($user['collections']) > 0): ?>
                            <?php 
                                $files = loadFiles();
                                $collections = array_filter($files, function($file) use ($user) {
                                    return in_array($file['id'], $user['collections']);
                                });
                                
                                if (count($collections) > 0):
                                    foreach ($collections as $file): 
                                        $owner = null; 
                                        $users = loadUsers();
                                        foreach ($users as $u) {
                                            if ($u['id'] == $file['owner_id']) {
                                                $owner = $u;
                                                break;
                                            }
                                        }
                            ?>
                                <div class="file-card" data-id="<?php echo $file['id']; ?>">
                                    <div class="file-header">
                                        <div class="file-name"><?php echo htmlspecialchars($file['name']); ?></div>
                                        <div class="file-id">ID: <?php echo $file['id']; ?></div>
                                    </div>
                                    <div class="file-body">
                                        <div class="file-desc"><?php echo htmlspecialchars($file['desc']); ?></div>
                                        <div class="file-meta">
                                            <div>
                                                <svg class="icon" viewBox="0 0 24 24">
                                                    <path d="M4,4A2,2 0 0,0 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8A2,2 0 0,0 20,6H12L10,4H4Z"/>
                                                </svg>
                                                大小: <?php echo formatSize($file['size']); ?>
                                            </div>
                                            <div>
                                                <svg class="icon" viewBox="0 0 24 24">
                                                    <path d="M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z"/>
                                                </svg>
                                                上传: <?php echo date('m-d H:i', strtotime($file['upload_time'])); ?>
                                            </div>
                                        </div>
                                        <div class="file-meta">
                                            <div>
                                                <svg class="icon" viewBox="0 0 24 24">
                                                    <path d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z"/>
                                                </svg>
                                                所有者: <?php echo $owner ? htmlspecialchars($owner['username']) : '未知'; ?>
                                            </div>
                                            <div>
                                                <svg class="icon" viewBox="0 0 24 24">
                                                    <path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/>
                                                </svg>
                                                下载: <?php echo $file['downloads']; ?>次
                                            </div>
                                        </div>
                                        <div class="file-price">
                                            <?php if ($file['price'] > 0): ?>
                                                <?php echo $file['price']; ?> 金币
                                            <?php else: ?>
                                                免费
                                            <?php endif; ?>
                                        </div>
                                        <div class="file-actions">
                                            <div class="action-btn like-btn <?php echo hasLiked($user['id'], $file['id']) ? 'liked' : ''; ?>" 
                                                onclick="likeFile(<?php echo $file['id']; ?>)">
                                                <svg viewBox="0 0 24 24">
                                                    <path d="M23,10C23,8.89 22.1,8 21,8H14.68L15.64,3.43C15.66,3.33 15.67,3.22 15.67,3.11C15.67,2.7 15.5,2.32 15.23,2.05L14.17,1L7.59,7.58C7.22,7.95 7,8.45 7,9V19A2,2 0 0,0 9,21H18C18.83,21 19.54,20.5 19.84,19.78L22.86,12.73C22.95,12.5 23,12.26 23,12V10M1,21H5V9H1V21Z"/>
                                                </svg>
                                                <?php echo $file['likes']; ?>
                                            </div>
                                            <div class="action-btn collect-btn collected" 
                                                onclick="collectFile(<?php echo $file['id']; ?>)">
                                                <svg viewBox="0 0 24 24">
                                                    <path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35Z"/>
                                                </svg>
                                                <?php echo $file['collections']; ?>
                                            </div>
                                            <?php if (hasPurchased($user['id'], $file['id'])): ?>
                                                <div class="action-btn download-btn" onclick="downloadFile(<?php echo $file['id']; ?>)">
                                                    <svg viewBox="0 0 24 24">
                                                        <path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/>
                                                    </svg>
                                                    下载
                                                </div>
                                            <?php else: ?>
                                                <div class="action-btn buy-btn" onclick="buyFile(<?php echo $file['id']; ?>)">
                                                    <svg viewBox="0 0 24 24">
                                                        <path d="M17,18A2,2 0 0,1 19,20A2,2 0 0,1 17,22C15.89,22 15,21.1 15,20C15,18.89 15.89,18 17,18M1,2H4.27L5.21,4H20A1,1 0 0,1 21,5C21,5.17 20.95,5.34 20.88,5.5L17.3,11.97C16.96,12.58 16.3,13 15.55,13H8.1L7.2,14.63L7.17,14.75A0.25,0.25 0 0,0 7.42,15H19V17H7C5.89,17 5,16.1 5,15C5,14.65 5.09,14.32 5.24,14.04L6.6,11.59L3,4H1V2M7,18A2,2 0 0,1 9,20A2,2 0 0,1 7,22C5.89,22 5,21.1 5,20C5,18.89 5.89,18 7,18M16,11L18.78,6H6.14L8.5,11H16Z"/>
                                                    </svg>
                                                    购买
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35M12,13A3,3 0 0,0 15,10A3,3 0 0,0 12,7A3,3 0 0,0 9,10A3,3 0 0,0 12,13Z"/>
                                    </svg>
                                    <h3>暂无收藏</h3>
                                    <p>您还没有收藏任何文件</p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24">
                                    <path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35M12,13A3,3 0 0,0 15,10A3,3 0 0,0 12,7A3,3 0 0,0 9,10A3,3 0 0,0 12,13Z"/>
                                </svg>
                                <h3>暂无收藏</h3>
                                <p>您还没有收藏任何文件</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 登录/注册 -->
        <?php if (!$user): ?>
            <div class="panel">
                <div id="login-section" class="section active">
                    <h2 class="panel-title">
                        <svg viewBox="0 0 24 24">
                            <path d="M10,17V14H3V10H10V7L15,12L10,17M10,2H19A2,2 0 0,1 21,4V20A2,2 0 0,1 19,22H10A2,2 0 0,1 8,20V18H10V20H19V4H10V6H8V4A2,2 0 0,1 10,2Z"/>
                        </svg>
                        用户登录
                    </h2>
                    <form method="post">
                        <input type="hidden" name="action" value="login">
                        <div class="form-group">
                            <label for="login-username">用户名</label>
                            <input type="text" name="username" id="login-username" required>
                        </div>
                        <div class="form-group">
                            <label for="login-password">密码</label>
                            <input type="password" name="password" id="login-password" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <svg viewBox="0 0 24 24">
                                <path d="M10,17V14H3V10H10V7L15,12L10,17M10,2H19A2,2 0 0,1 21,4V20A2,2 0 0,1 19,22H10A2,2 0 0,1 8,20V18H10V20H19V4H10V6H8V4A2,2 0 0,1 10,2Z"/>
                            </svg>
                            登录
                        </button>
                        <p style="margin-top: 15px; text-align: center;">
                            还没有账号？ <a href="#" onclick="showSection('register')" style="color: var(--primary);">立即注册</a>
                        </p>
                    </form>
                </div>
                
                <div id="register-section" class="section hidden">
                    <h2 class="panel-title">
                        <svg viewBox="0 0 24 24">
                            <path d="M15,14C12.33,14 7,15.33 7,18V20H23V18C23,15.33 17.67,14 15,14M6,10V7H4V10H1V12H4V15H6V12H9V10M15,12A4,4 0 0,0 19,8A4,4 0 0,0 15,4A4,4 0 0,0 11,8A4,4 0 0,0 15,12Z"/>
                        </svg>
                        用户注册
                    </h2>
                    <form method="post">
                        <input type="hidden" name="action" value="register">
                        <div class="form-group">
                            <label for="register-username">用户名</label>
                            <input type="text" name="username" id="register-username" required>
                        </div>
                        <div class="form-group">
                            <label for="register-password">密码</label>
                            <input type="password" name="password" id="register-password" required>
                        </div>
                        <div class="form-group">
                            <label for="register-confirm">确认密码</label>
                            <input type="password" name="confirm" id="register-confirm" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <svg viewBox="0 0 24 24">
                                <path d="M15,14C12.33,14 7,15.33 7,18V20H23V18C23,15.33 17.67,14 15,14M6,10V7H4V10H1V12H4V15H6V12H9V10M15,12A4,4 0 0,0 19,8A4,4 0 0,0 15,4A4,4 0 0,0 11,8A4,4 0 0,0 15,12Z"/>
                            </svg>
                            注册
                        </button>
                        <p style="margin-top: 15px; text-align: center;">
                            已有账号？ <a href="#" onclick="showSection('login')" style="color: var(--primary);">立即登录</a>
                        </p>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <footer>
            <p>文件交易平台 &copy; <?php echo date('Y'); ?> 版权所有</p>
            <p>PHP版本: <?php echo phpversion(); ?> | JSON存储系统</p>
        </footer>
    </div>
    
    <script>
        // 显示指定部分
        function showSection(sectionId) {
            // 隐藏所有部分
            document.querySelectorAll('.section').forEach(section => {
                section.classList.add('hidden');
                section.classList.remove('active');
            });
            
            // 显示选中的部分
            const section = document.getElementById(sectionId + '-section');
            if (section) {
                section.classList.remove('hidden');
                section.classList.add('active');
                
                // 更新菜单项选中状态
                if (sectionId !== 'files') {
                    document.querySelectorAll('.menu-item').forEach(item => {
                        item.classList.remove('active');
                    });
                    
                    // 找到对应的菜单项
                    const menuItems = document.querySelectorAll('.menu-item');
                    for (let i = 0; i < menuItems.length; i++) {
                        if (menuItems[i].textContent.includes(sectionId.charAt(0).toUpperCase() + sectionId.slice(1))) {
                            menuItems[i].classList.add('active');
                            break;
                        }
                    }
                }
            }
        }
        
        // 搜索文件
        function searchFiles() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase();
            const fileCards = document.querySelectorAll('.file-card');
            let found = false;
            
            fileCards.forEach(card => {
                const id = card.querySelector('.file-id').textContent.toLowerCase();
                const name = card.querySelector('.file-name').textContent.toLowerCase();
                const desc = card.querySelector('.file-desc').textContent.toLowerCase();
                
                if (id.includes(searchTerm) || name.includes(searchTerm) || desc.includes(searchTerm)) {
                    card.style.display = 'block';
                    found = true;
                } else {
                    card.style.display = 'none';
                }
            });
            
            if (!found) {
                document.getElementById('file-list').innerHTML = `
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24">
                            <path d="M15.5,12C18,12 20,14 20,16.5C20,17.38 19.75,18.21 19.31,18.9L22.39,22L21,23.39L17.88,20.32C17.19,20.75 16.37,21 15.5,21C13,21 11,19 11,16.5C11,14 13,12 15.5,12M15.5,14A2.5,2.5 0 0,0 13,16.5A2.5,2.5 0 0,0 15.5,19A2.5,2.5 0 0,0 18,16.5A2.5,2.5 0 0,0 15.5,14M10,4A4,4 0 0,1 14,8C14,8.91 13.69,9.75 13.18,10.43C12.32,10.75 11.55,11.26 10.91,11.9L10,12A4,4 0 0,1 6,8A4,4 0 0,1 10,4M2,20V18C2,15.88 5.31,14.14 9.5,14C9.18,14.78 9,15.62 9,16.5C9,17.79 9.38,19 10,20H2Z"/>
                        </svg>
                        <h3>未找到匹配文件</h3>
                        <p>没有找到与您的搜索匹配的文件</p>
                    </div>
                `;
            }
        }
        
        // 签到
        function sign() {
            const form = document.createElement('form');
            form.method = 'post';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'sign';
            
            form.appendChild(actionInput);
            document.body.appendChild(form);
            form.submit();
        }
        
        // 点赞文件
        function likeFile(fileId) {
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=like&file_id=${fileId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const btn = document.querySelector(`.file-card[data-id="${fileId}"] .like-btn`);
                    const likes = btn.textContent.match(/\d+/);
                    let count = likes ? parseInt(likes[0]) : 0;
                    
                    if (data.action === 'add') {
                        btn.classList.add('liked');
                        count++;
                    } else {
                        btn.classList.remove('liked');
                        count--;
                    }
                    
                    btn.innerHTML = `<svg viewBox="0 0 24 24"><path d="M23,10C23,8.89 22.1,8 21,8H14.68L15.64,3.43C15.66,3.33 15.67,3.22 15.67,3.11C15.67,2.7 15.5,2.32 15.23,2.05L14.17,1L7.59,7.58C7.22,7.95 7,8.45 7,9V19A2,2 0 0,0 9,21H18C18.83,21 19.54,20.5 19.84,19.78L22.86,12.73C22.95,12.5 23,12.26 23,12V10M1,21H5V9H1V21Z"/></svg>${count}`;
                } else {
                    alert(data.message);
                }
            });
        }
        
        // 收藏文件
        function collectFile(fileId) {
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=collect&file_id=${fileId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const btn = document.querySelector(`.file-card[data-id="${fileId}"] .collect-btn`);
                    const collections = btn.textContent.match(/\d+/);
                    let count = collections ? parseInt(collections[0]) : 0;
                    
                    if (data.action === 'add') {
                        btn.classList.add('collected');
                        count++;
                    } else {
                        btn.classList.remove('collected');
                        count--;
                    }
                    
                    btn.innerHTML = `<svg viewBox="0 0 24 24"><path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35Z"/></svg>${count}`;
                } else {
                    alert(data.message);
                }
            });
        }
        
        // 购买文件
        function buyFile(fileId) {
            if (!confirm('确定要购买此文件吗？')) return;
            
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=buy&file_id=${fileId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('购买成功！');
                    // 更新金币显示
                    document.querySelector('.user-stats span:nth-child(3)').textContent = `金币: ${data.gold}`;
                    // 更新按钮
                    const card = document.querySelector(`.file-card[data-id="${fileId}"]`);
                    card.querySelector('.buy-btn').classList.add('hidden');
                    const downloadBtn = document.createElement('div');
                    downloadBtn.className = 'action-btn download-btn';
                    downloadBtn.innerHTML = `<svg viewBox="0 0 24 24"><path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/></svg>下载`;
                    downloadBtn.onclick = () => downloadFile(fileId);
                    card.querySelector('.file-actions').appendChild(downloadBtn);
                } else {
                    alert(data.message);
                }
            });
        }
        
        // 下载文件
        function downloadFile(fileId) {
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=generate_download&file_id=${fileId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 使用临时URL下载文件
                    window.location.href = data.url;
                } else {
                    alert(data.message);
                }
            });
        }
        
        // 删除文件
        function deleteFile(fileId) {
            if (!confirm('确定要删除此文件吗？此操作不可恢复！')) return;
            
            const form = document.createElement('form');
            form.method = 'post';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_file';
            
            const fileInput = document.createElement('input');
            fileInput.type = 'hidden';
            fileInput.name = 'file_id';
            fileInput.value = fileId;
            
            form.appendChild(actionInput);
            form.appendChild(fileInput);
            document.body.appendChild(form);
            form.submit();
        }
        
        // 退出登录
        function logout() {
            window.location.href = 'index.php?logout=1';
        }
        
        // 开始文件上传
        function startUpload() {
            const fileInput = document.getElementById('file');
            const file = fileInput.files[0];
            
            if (!file) {
                alert('请选择文件');
                return;
            }
            
            const fileId = document.getElementById('file-id').value;
            const fileDesc = document.getElementById('file-desc').value;
            const filePrice = document.getElementById('file-price').value;
            
            const totalChunks = Math.ceil(file.size / <?php echo CHUNK_SIZE; ?>);
            const chunkSize = <?php echo CHUNK_SIZE; ?>;
            
            document.getElementById('progress-container').style.display = 'block';
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');
            
            let uploadedChunks = 0;
            
            // 上传每个分块
            for (let i = 0; i < totalChunks; i++) {
                const start = i * chunkSize;
                const end = Math.min(start + chunkSize, file.size);
                const chunk = file.slice(start, end);
                
                const formData = new FormData();
                formData.append('action', 'upload_chunk');
                formData.append('file_id', fileId);
                formData.append('chunk_index', i);
                formData.append('total_chunks', totalChunks);
                formData.append('chunk', chunk);
                
                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        uploadedChunks++;
                        const progress = Math.round((uploadedChunks / totalChunks) * 100);
                        progressBar.style.width = `${progress}%`;
                        progressText.textContent = `${progress}%`;
                        
                        // 所有分块上传完成
                        if (uploadedChunks === totalChunks) {
                            // 完成上传
                            const form = document.createElement('form');
                            form.method = 'post';
                            form.style.display = 'none';
                            
                            const actionInput = document.createElement('input');
                            actionInput.type = 'hidden';
                            actionInput.name = 'action';
                            actionInput.value = 'complete_upload';
                            
                            const idInput = document.createElement('input');
                            idInput.type = 'hidden';
                            idInput.name = 'file_id';
                            idInput.value = fileId;
                            
                            const nameInput = document.createElement('input');
                            nameInput.type = 'hidden';
                            nameInput.name = 'file_name';
                            nameInput.value = file.name;
                            
                            const descInput = document.createElement('input');
                            descInput.type = 'hidden';
                            descInput.name = 'file_desc';
                            descInput.value = fileDesc;
                            
                            const priceInput = document.createElement('input');
                            priceInput.type = 'hidden';
                            priceInput.name = 'price';
                            priceInput.value = filePrice;
                            
                            const chunksInput = document.createElement('input');
                            chunksInput.type = 'hidden';
                            chunksInput.name = 'total_chunks';
                            chunksInput.value = totalChunks;
                            
                            form.appendChild(actionInput);
                            form.appendChild(idInput);
                            form.appendChild(nameInput);
                            form.appendChild(descInput);
                            form.appendChild(priceInput);
                            form.appendChild(chunksInput);
                            
                            document.body.appendChild(form);
                            form.submit();
                        }
                    } else {
                        alert(`分块 ${i} 上传失败: ${data.message}`);
                    }
                })
                .catch(error => {
                    console.error('上传出错:', error);
                    alert(`分块 ${i} 上传失败`);
                });
            }
        }
        
        // 处理URL中的logout参数
        <?php if (isset($_GET['logout'])): ?>
            <?php session_destroy(); ?>
            window.location.href = 'index.php';
        <?php endif; ?>
    </script>
</body>
</html>