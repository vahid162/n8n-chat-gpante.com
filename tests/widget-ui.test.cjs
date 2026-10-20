const {test}=require('node:test');
const assert=require('node:assert/strict');
const {shortcutUrl}=require('../wordpress-plugin/gpante-support-chat/assets/chat.js');
test('administrator shortcut destinations are safe navigation URLs independent of model links',()=>{
 for(const value of ['javascript:alert(1)','data:text/html,x','http://example.com/','//example.com/','https://u:p@example.com/','/\\evil','/%0aevil','https://example.com/\n']) assert.equal(shortcutUrl(value),null,value);
 assert.equal(shortcutUrl('/shop/?q=test#products'),'https://gpante.com/shop/?q=test#products');
 assert.equal(shortcutUrl('https://example.com/help/'),'https://example.com/help/');
});
