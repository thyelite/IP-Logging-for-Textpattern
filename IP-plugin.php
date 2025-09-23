<?php
$plugin['name']        = 'VIS_IP_LOG';
$plugin['version']     = '1';
$plugin['author']      = 'C.S. Wilson';
$plugin['author_uri']  = 'https://www.hobbiesfordays.com/';
$plugin['description'] = 'Logs visitor IP addresses (IPv4/IPv6) to txp_log.ip_address and displays them on the Visitor logs panel.';
$plugin['type']        = 1;    // 4.8.x: 0=public, 1=admin+public, 2=library, 3=admin, 4=admin+ajax, 5=admin+public+ajax
$plugin['order']       = 5;    
$plugin['flags']       = 2;    // PLUGIN_LIFECYCLE_NOTIFY (so install/enable callbacks fire)

if (0) {
?>
# --- BEGIN PLUGIN HELP ---
hfd_ip_log
==========
- Ensures `txp_log.ip_address` exists (VARCHAR 45 + index) on install/enable.
- Captures IPv4/IPv6 (CF / X-Forwarded-For / X-Real-IP / REMOTE_ADDR).
- Shows an **IP Address** column on **Admin → Visitor logs** (server-side), with a silent DOM fallback/fill.
- IPs start storing when the plugin becomes active - it is not retroactive. So your IP Address column on Visitor Logs will be blank until you get visitors.
# --- END PLUGIN HELP ---
<?php
}
# --- BEGIN PLUGIN CODE ---

if (!defined('txpinterface')) {
    die('txp');
}

/* =========================================================
   ADMIN: INSTALL / ENSURE COLUMN (runs on install & enable)
   ========================================================= */
if (txpinterface === 'admin') {
    // Ensure column on install/enable
    register_callback('hfd_ip_install_or_enable', 'plugin_lifecycle.hfd_ip_log', 'installed');
    register_callback('hfd_ip_install_or_enable', 'plugin_lifecycle.hfd_ip_log', 'enabled');
    // Safety net: also verify on each admin page load
    register_callback('hfd_ip_admin_ensure_column', 'admin_side', 'head_end');
}

function hfd_ip_install_or_enable($evt, $step){ hfd_ip_ensure_column(); }
function hfd_ip_admin_ensure_column($evt, $step){ hfd_ip_ensure_column(); }

function hfd_ip_ensure_column()
{
    $tbl = safe_pfx('txp_log');
    $rs  = safe_query("SHOW COLUMNS FROM `{$tbl}` LIKE 'ip_address'");
    if (!($rs && nextRow($rs))) {
        safe_alter('txp_log', "ADD COLUMN `ip_address` VARCHAR(45) NULL DEFAULT NULL");
        @safe_query("ALTER TABLE `{$tbl}` ADD INDEX `ip_address` (`ip_address`)");
    }
}

/* =========================================================
   PUBLIC: CAPTURE & STORE IP
   ========================================================= */
if (txpinterface === 'public') {
    register_callback('hfd_ip_log_capture', 'log_hit');          // tolerant (2 or 3 args)
    register_callback('hfd_ip_after_render', 'textpattern_end'); // post-insert fallback
}

/* Pre-insert attempt (if TXP passes the log row by-ref). */
function hfd_ip_log_capture($evt=null, $stp=null, &$row=null)
{
    $ip = hfd_ip_detect();
    if (!$ip) return;
    $GLOBALS['hfd_ip_address'] = $ip; // stash for fallback

    if (is_array($row)) {
        $row['ip_address'] = $ip;
        return $row;
    }
}

/* Post-insert fallback: update newest matching row if needed. */
function hfd_ip_after_render($evt=null, $stp=null)
{
    $ip = isset($GLOBALS['hfd_ip_address']) ? $GLOBALS['hfd_ip_address'] : hfd_ip_detect();
    if (!$ip) return;

    $tbl  = safe_pfx('txp_log');
    $page = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $ipq  = doSlash($ip);
    $pgq  = doSlash($page);

    @safe_query("
        UPDATE `{$tbl}`
           SET `ip_address` = '{$ipq}'
         WHERE (`ip_address` IS NULL OR `ip_address` = '')
           AND `page` = '{$pgq}'
           AND `time` >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
         ORDER BY id DESC
         LIMIT 1
    ");
}

/* Determine client IP with proxy awareness (IPv4/IPv6). */
function hfd_ip_detect()
{
    $c = array();
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $c[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $x) {
            $x = trim($x);
            if ($x !== '') $c[] = $x;
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) $c[] = $_SERVER['HTTP_X_REAL_IP'];
    if (!empty($_SERVER['REMOTE_ADDR']))   $c[] = $_SERVER['REMOTE_ADDR'];

    foreach ($c as $ip) { // prefer public
        $ip = trim($ip, " []");
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
    }
    foreach ($c as $ip) { // else any valid
        $ip = trim($ip, " []");
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return null;
}

/* =========================================================
   ADMIN: VISITOR LOGS COLUMN (server-side + silent DOM fallback/fill)
   ========================================================= */
if (txpinterface === 'admin') {
    // Server-side hooks across common variants
    foreach (array('log','log_ui','lore','lore_ui') as $event) {
        foreach (array('list.head','list_head') as $s) register_callback('hfd_ip_head_cell', $event, $s);
        foreach (array('list.row','list_row')   as $s) register_callback('hfd_ip_row_cell',  $event, $s);
    }
    // DOM fallback + client-side fill (no debug UI)
    register_callback('hfd_ip_dom_helpers', 'admin_side', 'body_end');
}

/* Helper: am I on Visitor logs? (4.8 = log, 4.9 = lore) */
function hfd_is_logs_panel()
{
    $ev = gps('event');
    return ($ev === 'log' || $ev === 'lore');
}

/* Header cell: “IP Address” */
function hfd_ip_head_cell($evt, $stp, $data, $rs)
{
    if (!hfd_is_logs_panel()) return $data;
    if (strpos($data, 'txp-list-col-ip_address') !== false) return $data;

    $th = "\n\t".'<th class="txp-list-col-ip_address" data-col="ip_address" scope="col">IP Address</th>';

    $out = preg_replace('~(<th[^>]*class="[^"]*txp-list-col-status[^"]*"[^>]*>.*?</th>)~i', '$1'.$th, $data, 1);
    return ($out !== null && $out !== $data) ? $out : str_replace('</tr>', $th.'</tr>', $data);
}

/* Row cell: populate with ip_address (robust row id detection). */
function hfd_ip_row_cell($evt, $stp, $data, $rs)
{
    if (!hfd_is_logs_panel()) return $data;
    if (strpos($data, 'txp-list-col-ip_address') !== false) return $data;

    // 1) Try id from recordset
    $id = !empty($rs['id']) ? (int)$rs['id'] : 0;

    // 2) Fallback: parse id from checkbox value in the rendered row
    if (!$id) {
        if (preg_match('~name=[\'"]selected\[\][\'"][^>]*\bvalue=[\'"](\d+)[\'"]~i', $data, $m)) {
            $id = (int)$m[1];
        } elseif (preg_match('~\bvalue=[\'"](\d+)[\'"]~i', $data, $m)) {
            $id = (int)$m[1];
        }
    }

    // 3) Fetch IP by id
    $ip = ($id > 0) ? (string) safe_field('ip_address', 'txp_log', 'id = '.$id) : '';

    $td = "\n\t".'<td class="txp-list-col-ip_address">'.txpspecialchars($ip).'</td>';

    $out = preg_replace('~(<td[^>]*class="[^"]*txp-list-col-status[^"]*"[^>]*>.*?</td>)~i', '$1'.$td, $data, 1);
    return ($out !== null && $out !== $data) ? $out : str_replace('</tr>', $td.'</tr>', $data);
}

/* DOM fallback + client-side fill: ensure header/cells exist; fill from a compact JSON map. */
function hfd_ip_dom_helpers($evt, $stp)
{
    if (!hfd_is_logs_panel()) return;

    // Build a compact id->ip map for recent rows (no Ajax needed)
    $map = array();
    $rs = safe_rows_start('id, ip_address', safe_pfx('txp_log'),
                          "ip_address IS NOT NULL AND ip_address <> '' ORDER BY id DESC LIMIT 400");
    if ($rs) {
        while ($a = nextRow($rs)) {
            $map[(int)$a['id']] = (string)$a['ip_address'];
        }
    }
    $json = json_encode($map);

    echo <<<HTML
<script>
(function(){
  try{
    var table=document.querySelector('.txp-listtables table.txp-list');
    if(!table) return;

    // Ensure header exists
    if(!table.querySelector('th.txp-list-col-ip_address')){
      var headRow=table.querySelector('thead tr');
      if(headRow){
        var th=document.createElement('th');
        th.className='txp-list-col-ip_address';
        th.setAttribute('data-col','ip_address');
        th.setAttribute('scope','col');
        th.textContent='IP Address';
        headRow.appendChild(th);
      }
    }

    // Ensure cells exist & collect id->cell map
    var rows=table.querySelectorAll('tbody tr');
    var idToCell={}, ids=[];
    rows.forEach(function(tr){
      var cb=tr.querySelector('input[name="selected[]"]');
      if(!cb || !cb.value) return;
      var id=cb.value.trim();
      var cell=tr.querySelector('td.txp-list-col-ip_address');
      if(!cell){
        cell=document.createElement('td');
        cell.className='txp-list-col-ip_address';
        tr.appendChild(cell);
      }
      idToCell[id]=cell;
      ids.push(id);
    });

    // Fill from server-emitted map
    var MAP = $json || {};
    ids.forEach(function(id){
      var ip = MAP[id];
      if(ip && idToCell[id] && !idToCell[id].textContent){
        idToCell[id].textContent = ip;
      }
    });
  }catch(e){}
})();
</script>
HTML;
}

# --- END PLUGIN CODE ---
?>
