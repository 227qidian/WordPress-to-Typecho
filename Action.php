<?php

use Typecho\Widget;
use Widget\ActionInterface;
use Typecho\Db;
use Typecho\Common;

class WordpressToTypecho_Action extends Widget implements ActionInterface
{
    public function doImport()
    {
        try {
            $options = $this->widget('Widget\Options');
            $dbConfig = $options->plugin('WordpressToTypecho');

            /** 初始化一个db */
            $adapter = 'Pdo_Mysql';
            if (class_exists('\Typecho\Db\Adapter\Mysql') && \Typecho\Db\Adapter\Mysql::isAvailable()) {
                $adapter = 'Mysql';
            }
            $db = new Db($adapter, $dbConfig->prefix);

            /** 只读即可 */
            $db->addServer(array (
              'host' => $dbConfig->host,
              'user' => $dbConfig->user,
              'password' => $dbConfig->password,
              'charset' => 'utf8mb4',
              'port' => $dbConfig->port,
              'database' => $dbConfig->database
            ), Db::READ);
            
            /** 删除当前内容 */
            $masterDb = Db::get();
            $masterDb->query($masterDb->delete('table.contents')->where('1 = 1'));
            $masterDb->query($masterDb->delete('table.comments')->where('1 = 1'));
            $masterDb->query($masterDb->delete('table.metas')->where('1 = 1'));
            $masterDb->query($masterDb->delete('table.relationships')->where('1 = 1'));
            $masterDb->query($masterDb->delete('table.fields')->where('1 = 1'));
            
            /** 获取当前Typecho用户ID和时区偏移 */
            $userId = $this->widget('Widget\User')->uid;
            $gmtOffset = idate('Z');
            
            /** 转换评论（只导入 post/page 类型文章的评论） */
            $i = 1;
            
            while (true) {
                $result = $db->query($db->select()->from('table.comments')
                ->join('table.posts', 'table.comments.comment_post_ID = table.posts.ID')
                ->where('table.posts.post_type = ? OR table.posts.post_type = ?', 'post', 'page')
                ->order('comment_ID', Db::SORT_ASC)->page($i, 100));
                $j = 0;
                
                while ($row = $db->fetchRow($result)) {
                    $status = $row['comment_approved'];
                    if ('spam' == $row['comment_approved']) {
                        $status = 'spam';
                    } else if ('0' == $row['comment_approved']) {
                        $status = 'waiting';
                    } else {
                        $status = 'approved';
                    }
                    
                    $row['comment_content'] = preg_replace(
                    array("/\s*<p>/is", "/\s*<\/p>\s*/is", "/\s*<br\s*\/>\s*/is",
                    "/\s*<(div|blockquote|pre|table|ol|ul)>/is", "/<\/(div|blockquote|pre|table|ol|ul)>\s*/is"),
                    array('', "\n\n", "\n", "\n\n<\\1>", "</\\1>\n\n"), 
                    $row['comment_content']);
                
                    /** type 字段白名单过滤 */
                    $commentType = empty($row['comment_type']) ? 'comment' : $row['comment_type'];
                    if (!in_array($commentType, array('comment', 'pingback', 'trackback'))) {
                        $commentType = 'comment';
                    }
                    
                    /** created 字段兜底 */
                    $createdTime = strtotime($row['comment_date_gmt']);
                    if ($createdTime === false || $createdTime < 0) {
                        $createdTime = time();
                    }
                
                    $masterDb->query($masterDb->insert('table.comments')->rows(array(
                        'coid'      =>  $row['comment_ID'],
                        'cid'       =>  $row['comment_post_ID'],
                        'created'   =>  $createdTime + $gmtOffset,
                        'author'    =>  empty($row['comment_author']) ? '匿名' : $row['comment_author'],
                        'authorId'  =>  0,
                        'ownerId'   =>  $userId,
                        'mail'      =>  empty($row['comment_author_email']) ? '' : $row['comment_author_email'],
                        'url'       =>  empty($row['comment_author_url']) ? '' : $row['comment_author_url'],
                        'ip'        =>  empty($row['comment_author_IP']) ? '' : $row['comment_author_IP'],
                        'agent'     =>  empty($row['comment_agent']) ? '' : $row['comment_agent'],
                        'text'      =>  $row['comment_content'],
                        'type'      =>  $commentType,
                        'status'    =>  $status,
                        'parent'    =>  intval($row['comment_parent'])
                    )));
                    $j ++;
                    unset($row);
                }
                
                if ($j < 100) {
                    break;
                }
                
                $i ++;
                unset($result);
            }
            
            /** 转换Wordpress的term_taxonomy表 */
            $terms = $db->fetchAll($db->select()->from('table.term_taxonomy')
            ->join('table.terms', 'table.term_taxonomy.term_id = table.terms.term_id')
            ->where('taxonomy = ? OR taxonomy = ?', 'category', 'post_tag'));
            foreach ($terms as $term) {
                $slug = 'post_tag' == $term['taxonomy'] 
                    ? (method_exists(Common::class, 'slugName') ? Common::slugName($term['name']) : $term['slug']) 
                    : $term['slug'];
                
                $masterDb->query($masterDb->insert('table.metas')->rows(array(
                    'mid'           =>  $term['term_taxonomy_id'],
                    'name'          =>  $term['name'],
                    'slug'          =>  $slug,
                    'type'      	=>  'post_tag' == $term['taxonomy'] ? 'tag' : 'category',
                    'description'   =>  $term['description'],
                    'count'      	=>  $term['count'],
                    'order'         =>  0,
                    'parent'        =>  0,
                )));
                
                /** 转换关系表 */
                $relationships = $db->fetchAll($db->select()->from('table.term_relationships')
                ->where('term_taxonomy_id = ?', $term['term_taxonomy_id']));
                foreach ($relationships as $relationship) {
                    $masterDb->query($masterDb->insert('table.relationships')->rows(array(
                        'cid'      	=>  $relationship['object_id'],
                        'mid'   	=>  $relationship['term_taxonomy_id'],
                    )));
                }
            }
            
            /** 转换内容 */
            $i = 1;
            
            while (true) {
                $result = $db->query($db->select()->from('table.posts')
                ->where('post_type = ? OR post_type = ?', 'post', 'page')
                ->order('ID', Db::SORT_ASC)->page($i, 100));
                $j = 0;
                
                while ($row = $db->fetchRow($result)) {
                    $slug = method_exists(Common::class, 'slugName') 
                        ? Common::slugName(urldecode($row['post_name']), strval($row['ID']), 128) 
                        : (empty($row['post_name']) ? strval($row['ID']) : $row['post_name']);
                    
                    $created = strtotime($row['post_date_gmt']) + $gmtOffset;
                    $modified = strtotime($row['post_modified_gmt']) + $gmtOffset;
                    
                    $masterDb->query($masterDb->insert('table.contents')->rows(array(
                        'cid'           =>  $row['ID'],
                        'title'         =>  $row['post_title'],
                        'slug'          =>  $slug,
                        'created'       =>  $created > 0 ? $created : 0,
                        'modified'      =>  $modified > 0 ? $modified : 0,
                        'text'          =>  $row['post_content'],
                        'order'         =>  intval($row['menu_order']),
                        'authorId'      =>  $userId,
                        'template'      =>  NULL,
                        'type'          =>  'page' == $row['post_type'] ? 'page' : 'post',
                        'status'        =>  'publish' == $row['post_status'] ? 'publish' : 'draft',
                        'password'      =>  $row['post_password'],
                        'commentsNum'   =>  intval($row['comment_count']),
                        'allowComment'  =>  'open' == $row['comment_status'] ? 1 : 0,
                        'allowFeed'     =>  1,
                        'allowPing'     =>  'open' == $row['ping_status'] ? 1 : 0,
                        'parent'        =>  0,
                    )));
                    
                    $j ++;
                    unset($row);
                }
                
                if ($j < 100) {
                    break;
                }
                
                $i ++;
                unset($result);
            }
            
            $this->widget('Widget\Notice')->set(_t("数据已经转换完成"), NULL, 'success');
            $this->response->goBack();
            
        } catch (\Throwable $e) {
            throw new \Typecho\Widget\Exception(
                _t('导入失败: %s', $e->getMessage())
            );
        }
    }

    public function action()
    {
        $this->widget('Widget\User')->pass('administrator');
        $this->on($this->request->isPost())->doImport();
    }
}
