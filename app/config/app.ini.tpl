[common]
; Алфавит для кодирование чисел по словарю
alphabet    = 'nGWZFAQcUxV2fqJtMmyR7BHwPXNrL9DijhCsvuaezpTS3gEdk546Yb8K'
epoch       = 1606402632000
secret      = ''

trigger_map_file   = '{{VAR_DIR}}/trigger_event_map.json'
trigger_param_file = '{{VAR_DIR}}/trigger_param_map.json'
uri_map_file	  = '{{VAR_DIR}}/uri_request_map.json'
param_map_file    = '{{VAR_DIR}}/import_var_map.json'
action_map_file = '{{VAR_DIR}}/action_map.json'

upload_max_filesize = '10M'

proto = 'http'
domain = '{{PROJECT}}.lo'

[common:test]
domain = '{{PROJECT}}.dev'

[common:production]
domain = '{{PROJECT}}.ru'

[default]
action = 'home'

[view]
source_dir          = '{{APP_DIR}}/views'
compile_dir         = '{{TMP_DIR}}/{{PROJECT_REV}}'
template_extension  = 'tpl'
strip_comments      = false
merge_lines         = false

[view:production]
strip_comments = true
merge_lines    = true

[session]
name          = 'KISS'

[nginx]
port = 80
auth_name = 'test'
auth_pass = 'test'
; auth_basic nginx param: off, Restricted
auth = 'off'
open_file_cache = 'off'
protocol = ''

[nginx:production]
open_file_cache = 'max=100000 inactive=600s'

[nginx:test]
auth = 'Restricted'

[cors]
origin = '*'
methods = 'GET, POST, PUT, DELETE, OPTIONS'
headers = 'DNT,X-CustomHeader,Keep-Alive,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type'
credentials = 'true'

[mysql]
connect_timeout=1
shard[0] = 'mysql:host=db.0;port=3333;dbname=fake;user=fake;password=Hsx2FQJ7sNRAmfPnwO01'

[mysql:production]
shard[0] = 'mysql:host=127.0.0.1;port=3333;dbname=fake;user=fake;password=Hsx2FQJ7sNRAmfPnwO01'


[memcache]
host = cache.0
port = 4444
persistent = 0
compression = true
binary_protocol = true
connect_timeout = 5
retry_timeout = 5
send_timeout = 5
recv_timeout = 5
poll_timeout = 5
key_prefix = '{{PROJECT}}:'

[memcache:production]
host = 127.0.0.1



