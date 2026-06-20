<?php
/**
 * 将 WordPress 数据库中的数据转换为 Typecho
 * 
 * @package WordPress to Typecho
 * @author yuege.
 * @Original author qining   link http://typecho.org
 * @version 1.1.3 Beta
 * @link https://beicb.top
 */

use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;

class WordpressToTypecho_Plugin implements PluginInterface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws PluginException
     */
    public static function activate()
    {
        $hasAdapter = false;
        if (class_exists('\Typecho\Db\Adapter\Mysql') && \Typecho\Db\Adapter\Mysql::isAvailable()) {
            $hasAdapter = true;
        } elseif (class_exists('\Typecho\Db\Adapter\Pdo\Mysql') && \Typecho\Db\Adapter\Pdo\Mysql::isAvailable()) {
            $hasAdapter = true;
        }

        if (!$hasAdapter) {
            throw new PluginException(_t('没有找到任何可用的 Mysql 适配器'));
        }

        Helper::addPanel(1, 'WordpressToTypecho/panel.php', _t('从 WordPress 导入数据'), _t('从 WordPress 导入数据'), 'administrator');
        Helper::addAction('wordpress-to-typecho', 'WordpressToTypecho_Action');
        return _t('请在插件设置里设置 WordPress 所在的数据库参数');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws PluginException
     */
    public static function deactivate()
    {
        Helper::removeAction('wordpress-to-typecho');
        Helper::removePanel(1, 'WordpressToTypecho/panel.php');
    }
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Form $form 配置面板
     * @return void
     */
    public static function config(Form $form)
    {
        $host = new Text('host', NULL, 'localhost',
        _t('数据库地址'), _t('请填写 WordPress 所在的数据库地址'));
        $form->addInput($host->addRule('required', _t('必须填写一个数据库地址')));
        
        $port = new Text('port', NULL, '3306',
        _t('数据库端口'), _t('WordPress 所在的数据库服务器端口'));
        $port->input->setAttribute('class', 'mini');
        $form->addInput($port->addRule('required', _t('必须填写数据库端口'))
        ->addRule('isInteger', _t('端口号必须是纯数字')));
        
        $user = new Text('user', NULL, 'root',
        _t('数据库用户名'));
        $form->addInput($user->addRule('required', _t('必须填写数据库用户名')));
        
        $password = new Password('password', NULL, NULL,
        _t('数据库密码'));
        $form->addInput($password);
        
        $database = new Text('database', NULL, 'wordpress',
        _t('数据库名称'), _t('WordPress 所在的数据库名称'));
        $form->addInput($database->addRule('required', _t('您必须填写数据库名称')));
    
        $prefix = new Text('prefix', NULL, 'wp_',
        _t('表前缀'), _t('所有 WordPress 数据表的前缀'));
        $form->addInput($prefix->addRule('required', _t('您必须填写表前缀')));
    }
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form){}
}
