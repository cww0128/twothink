<?php
// 检测环境是否支持可写
define('IS_WRITE', true);
define('INSTALL_APP_PATH', realpath('./') . '/');

/**
 * 系统环境检测
 * @return array 系统环境数据
 */
function check_env() {
    $items = array(
        'os' => array('操作系统', '不限制', '类Unix', PHP_OS, 'ok'),
        'php' => array('PHP版本', '5.4', '5.4+', PHP_VERSION, 'ok'),
        'upload' => array('附件上传', '不限制', '2M+', '未知', 'ok'),
        'gd' => array('GD库', '2.0', '2.0+', '未知', 'ok'),
        'curl' => array('Curl扩展', '开启', '不限制', '未知', 'ok'),
        'disk' => array('磁盘空间', '5M', '不限制', '未知', 'ok'),
    );

    //PHP环境检测
    if ($items['php'][3] < $items['php'][1]) {
        $items['php'][4] = 'remove';
        session('error', true);
    }

    //附件上传检测
    if (@ini_get('file_uploads')) {
        $items['upload'][3] = ini_get('upload_max_filesize');
    }

    //GD库检测
    $tmp = function_exists('gd_info') ? gd_info() : array();
    if (empty($tmp['GD Version'])) {
        $items['gd'][3] = '未安装';
        $items['gd'][4] = 'remove';
        session('error', true);
    } else {
        $items['gd'][3] = $tmp['GD Version'];
    }
    unset($tmp);

    $tmp = function_exists('curl_init') ? curl_version() : array();
    if (empty($tmp['version'])) {
        $items['curl'][3] = '未安装';
        $items['curl'][4] = 'remove';
        session('curl', true);
    } else {
        $items['curl'][3] = $tmp['version'];
    }
    unset($tmp);
    //磁盘空间检测
    if (function_exists('disk_free_space')) {
        $items['disk'][3] = floor(disk_free_space(INSTALL_APP_PATH) / (1024 * 1024)) . 'M';
    }

    return $items;
}

/**
 * 目录，文件读写检测
 * @return array 检测数据
 */
function check_dirfile() {
    $items = array(
        array('dir', '可写', 'ok', '../data'),
        array('dir', '可写', 'ok', '../runtime'),
    );

    foreach ($items as &$val) {
        if ('dir' == $val[0]) {
            if (!is_writable(INSTALL_APP_PATH . $val[3])) {
                if (is_dir(INSTALL_APP_PATH . $val[3])) {
                    $val[1] = '可读';
                    $val[2] = 'remove';
                } else {
                    $val[1] = INSTALL_APP_PATH . $val[3] . '不存在或者不可写';
                    $val[2] = 'remove';
                }

                session('error', true);
            }
        } else {
            if (file_exists(INSTALL_APP_PATH . $val[3])) {
                if (!is_writable(INSTALL_APP_PATH . $val[3])) {
                    $val[1] = '文件存在但不可写';
                    $val[2] = 'remove';
                    session('error', true);
                }
            } else {
                if (!is_writable(dirname(INSTALL_APP_PATH . $val[3]))) {
                    $val[1] = '不存在或者不可写';
                    $val[2] = 'remove';
                    session('error', true);
                }
            }
        }
    }

    return $items;
}

/**
 * 函数检测
 * @return array 检测数据
 */
function check_func() {
    $items = array(
        array('file_get_contents', '支持', 'ok'),
        array('mb_strlen', '支持', 'ok'),
        array('curl_init', '支持', 'ok'),
    );

    if (function_exists('mysqli_connect')) {
        $items[] = array('mysqli_connect', '支持', 'ok');
    } else {
        $items[] = array('mysql_connect', '支持', 'ok');
    }

    foreach ($items as &$val) {
        if (!function_exists($val[0])) {
            $val[1] = '不支持';
            $val[2] = 'remove';
            $val[3] = '开启';
            session('error', true);
        }
    }

    return $items;
}

/**
 * 及时显示提示信息
 * @param  string $msg 提示信息
 */
function show_msg($msg, $class = '') {
    echo "<script type=\"text/javascript\">showmsg(\"{$msg}\", \"{$class}\")</script>";
    ob_flush();
    flush();
}

/**
 * 创建数据表
 * @param  resource $db 数据库连接资源
 */
function create_tables($db, $prefix = '') {
    //读取SQL文件
    $sql = file_get_contents(APP_PATH . 'install/data/install.sql');
    $sql = str_replace("\r", "\n", $sql);
    $sql = explode(";\n", $sql);

    //替换表前缀
    $orginal = \think\Config::get('ORIGINAL_TABLE_PREFIX');
    $sql = str_replace(" `{$orginal}", " `{$prefix}", $sql);

    //开始安装
    show_msg('开始安装数据库...');
    foreach ($sql as $value) {
        $value = trim($value);
        if (empty($value)) {
            continue;
        }
        if (substr($value, 0, 12) == 'CREATE TABLE') {
            $name = preg_replace("/^CREATE TABLE IF NOT EXISTS `(\w+)` .*/s", "\\1", $value);
            $msg = "创建数据表{$name}";
            if (false !== $db->execute($value)) {
                show_msg($msg . '...成功');
            } else {
                show_msg($msg . '...失败！', 'error');
                session('error', true);
            }
        } else {
            $db->execute($value);
        }
    }
}

function register_administrator($db, $prefix, $admin) {
    show_msg('开始注册创始人帐号...');
    $uid = 1;
    /*插入用户*/
    $sql = <<<sql
REPLACE INTO `[PREFIX]ucenter_member` (`id`, `username`, `password`, `email`, `mobile`, `reg_time`, `reg_ip`, `last_login_time`, `last_login_ip`, `update_time`, `status`, `type`) VALUES
('[UID]', '[NAME]', '[PASS]','[EMAIL]', '', '[TIME]', '[IP]', '[TIME]', '[IP]',  '[TIME]', 1, 1);
sql;

    /*  "REPLACE INTO `[PREFIX]ucenter_member` VALUES " .
         "('1', '[NAME]', '[PASS]', '[EMAIL]', '', '[TIME]', '[IP]', 0, 0, '[TIME]', '1',1,'finish')";*/

    $password = md5($admin['password']);
    $sql = str_replace(
        array(
            '[PREFIX]',
            '[NAME]',
            '[PASS]',
            '[EMAIL]',
            '[TIME]',
            '[IP]',
            '[UID]'
        ),
        array(
            $prefix,
            $admin['username'],
            $password,
            $admin['email'],
            time(),
            get_client_ip(1),
            $uid
        ),
        $sql);
    //执行sql
    $db->execute($sql);

    /*插入用户资料*/
    $sql = <<<sql
REPLACE INTO `[PREFIX]member` (`uid`, `nickname`, `sex`, `birthday`, `qq`, `login`, `reg_ip`, `reg_time`, `last_login_ip`, `last_login_role`, `show_role`, `last_login_time`, `status`, `signature`) VALUES
('[UID]','[NAME]', 0,  '0', '', 1, 0, '[TIME]', 0, 1, 1, '[TIME]', 1, '');
sql;

    $sql = str_replace(
        array('[PREFIX]', '[NAME]', '[TIME]', '[UID]'),
        array($prefix, $admin['username'], time(), $uid),
        $sql);

    $db->execute($sql);

    show_msg('创始人帐号注册完成！');
}

/**
 * 写入配置文件
 * @param  array $config 配置信息
 */
function write_config($config) {
    if (is_array($config)) {
        //读取配置内容
        $conf = file_get_contents(APP_PATH . 'database.php');
        //替换配置项
        foreach ($config as $name => $value) {
            $conf = str_replace("[database{$name}]", $value, $conf);
        }

        //写入应用配置文件
        if (file_put_contents(APP_PATH . 'database.php', $conf)) {
            chmod(APP_PATH . 'database.php', 0777);
            show_msg('配置文件写入成功');
        } else {
            show_msg('配置文件写入失败！', 'error');
            session('error', true);
        }
        return '';
    }
}