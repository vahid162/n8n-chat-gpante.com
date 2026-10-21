<?php
// No network or database: exercise the actual enrichment trust boundary.
define('ABSPATH',__DIR__);
function wp_strip_all_tags($v){return strip_tags($v);}
function wp_html_excerpt($v,$n,$more){return $v;}
function get_the_title($id){return '<b>Public '.$id.'</b>';}
$lookups=0;$products=array();$posts=array();$images=array();
function url_to_postid($url){$GLOBALS['lookups']++;return (int)basename(parse_url($url,PHP_URL_PATH));}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_permalink($id){return 'https://gpante.com/product/'.$id.'/';}
function wc_get_product($id){return $GLOBALS['products'][$id]??false;}
function wp_get_attachment_image_url($id,$size){if($size!=='thumbnail')throw new RuntimeException('Wrong image size');return $GLOBALS['images'][$id]??false;}
class PreviewProduct{
 public function __construct(public $id,public $status='publish',public $type='simple',public $visibility='visible'){}
 public function get_status(){return $this->status;}
 public function get_type(){return $this->type;}
 public function get_catalog_visibility(){return $this->visibility;}
 public function get_image_id(){return $this->id;}
}
require __DIR__.'/../wordpress-plugin/gpante-support-chat/includes/catalog.php';
function check_preview($yes,$message){if(!$yes)throw new RuntimeException($message);}
foreach(['visible','catalog','search','hidden','private','password','variation','other','missing_image','external_image','svg_image','encoded_traversal'] as $i=>$kind){
 $id=$i+1;$post=(object)['post_type'=>$kind==='other'?'post':'product','post_status'=>$kind==='private'?'private':'publish','post_password'=>$kind==='password'?'secret':''];
 $posts[$id]=$post;$products[$id]=new PreviewProduct($id,'publish',$kind==='variation'?'variation':'simple',in_array($kind,['visible','catalog','search','hidden'],true)?$kind:'visible');
 $images[$id]=$kind==='missing_image'?false:($kind==='external_image'?'https://evil.example/image.jpg':($kind==='svg_image'?'https://gpante.com/wp-content/uploads/a.svg':($kind==='encoded_traversal'?'https://gpante.com/wp-content/uploads/%2e%2e/private.jpg':'https://gpante.com/wp-content/uploads/'.$id.'-150x150.webp')));
 $cache=[];$out=gpante_support_reply_products('[View](https://gpante.com/product/'.$id.'/)',$cache);
 check_preview(count($out)===(in_array($kind,['visible','catalog','search'],true)?1:0),'Wrong public eligibility for '.$kind);
 if($out)check_preview(array_keys($out[0])===['url','title','thumbnail'] && $out[0]['title']==='Public '.$id,'Only plain public preview fields');
}
$cache=[];$lookups=0;
$out=gpante_support_reply_products('[a](https://gpante.com/product/1/) [same](https://gpante.com/product/1/) https://evil.example/product/1/ https://gpante.com/product/1/?token=private https://gpante.com/product/%2e%2e/1/',$cache);
check_preview(count($out)===1&&$lookups===1,'Only one canonical public lookup, no foreign/query/traversal URL');
gpante_support_reply_products('https://gpante.com/product/1/',$cache);check_preview($lookups===1,'Shared per-request cache avoids repeat work');
$cache=[];$lookups=0;gpante_support_reply_products(implode(' ',array_map(fn($i)=>'https://gpante.com/product/'.$i.'/',range(1,60))),$cache);check_preview($lookups===30,'Bound lookups without limiting catalog eligibility');
check_preview(!gpante_support_reply_url('https://gpante.com/product/%255cprivate/','product/'),'Double encoding rejected');
echo "Product previews: eligibility, fixed URLs, thumbnail size, field allowlist, cache and bounds passed.\n";
