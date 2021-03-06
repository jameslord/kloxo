<?php 

// issue #598 - Change lighhtpd config structure

class web__lighttpd extends lxDriverClass {

//######################################### SyncToSystem Starts Here

static function uninstallMe()
{
	global $gbl, $sgbl, $login, $ghtml; 

	lxshell_return("service",  "lighttpd", "stop");
	lxshell_return("rpm", "-e", "--nodeps", "lighttpd");
	if (file_exists("/etc/init.d/lighttpd")) {
		lunlink("/etc/init.d/lighttpd");
	}
}

static function installMe()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$ret = lxshell_return("yum", "-y", "install", "lighttpd", "lighttpd-fastcgi");
	if ($ret) { throw new lxexception('install_lighttpd_failed', 'parent'); }
	lxshell_return("chkconfig", "lighttpd", "on");

	lxfile_mkdir("/etc/lighttpd/");

	//-- old structure
	lxfile_rm("/etc/lighttpd/conf/kloxo");

	//-- new structure	
	$path = "/home/lighttpd/conf";

	$list = array("defaults", "domains", "redirects", "webmails", "wildcards", "exclusive");

	foreach($list as $k => $l) {
		if (!lxfile_exists("{$path}/{$l}")) {
			lxfile_mkdir("{$path}/{$l}");
		}
	}

	lxfile_cp("/usr/local/lxlabs/kloxo/file/lighttpd/lighttpd.conf", "/etc/lighttpd/lighttpd.conf");

	$cver = "###version0-7###";
	$fver = file_get_contents("/etc/lighttpd/conf.d/~lxcenter.conf");
	
	if(stristr($fver, $cver) === FALSE) {
		lxfile_cp("/usr/local/lxlabs/kloxo/file/lighttpd/~lxcenter.conf", "/etc/lighttpd/conf.d/~lxcenter.conf");
	}

	lxfile_cp("../file/lighttpd/etc_init.d", "/etc/init.d/lighttpd");
	lxfile_unix_chmod("/etc/init.d/lighttpd", "0755");
	lxfile_unix_chmod("/etc/init.d/lighttpd", "0755");
	lxfile_mkdir("/home/kloxo/httpd/lighttpd");

	lxfile_unix_chown("/home/kloxo/httpd/lighttpd", "apache");

	createRestartFile("lighttpd");
}

function getRailsConf($app)
{
	global $gbl, $sgbl, $login, $ghtml;

	if ($this->isRailsDocroot() && !$app->isOn('accessible_directly')) {
		return '';
	}

	$appname = $app->appname;

	$appurl = null;

	if (!$app->isOn('accessible_directly')) {
		$appurl = "/{$appname}";
	}

	if ($app->priv->rubyfcgiprocess_num > 0) {
		$proc = $app->priv->rubyfcgiprocess_num;
	} else {
		$proc = 1;
	}

	$basepath = "/home/{$this->main->customer_name}/ror/{$this->main->nname}/";
	$uid = os_get_uid_from_user($this->main->username);
	$gid = os_get_gid_from_user($this->main->username);

	$string  = null;

	if (!$app->isOn('accessible_directly')) {
		$string .= "\$HTTP[\"url\"] =~ \"^/{$appname}\" {\n";
	}

	$string .= "\tserver.document-root = \"{$basepath}/{$appname}/public/\"\n";

	if (!$app->isOn('accessible_directly')) {
		//$string .= "\t\talias.url = ( \"{$appurl}/\" => \"{$basepath}/{$appname}/public/\" )\n";
	} else {
		$string .= "\talias.url += ( \"{$appurl}/\" => \"{$basepath}/{$appname}/public/\" )\n";
	}

	$string .= "\tserver.error-handler-404 = \"{$appurl}/dispatch.fcgi\"\n\n";
	$string .= "\tfastcgi.server = ( \".fcgi\" => (( \"socket\" => \"/tmp/ror.socket.mick.com.{$appname}.\" + var.PID,\n";
	$string .= "\t\"bin-path\" => \"/usr/bin/lxsuexec\",\n";
	$string .= "\t\t\"min-procs\" => 0,\n";
	$string .= "\t\t\"max-procs\" => {$proc},\n";
	$string .= "\t\t\t\"bin-environment\" => (\n";
	$string .= "\t\t\t\t\"MUID\" => \"{$uid}\",\n";
	$string .= "\t\t\t\t\"GID\" => \"{$gid}\",\n";
	$string .= "\t\t\t\t\"TARGET\" => \"{$basepath}/{$appname}/public/dispatch.fcgi\",\n";
	$string .= "\t\t\t\t\"NON_RESIDENT\" => \"1\"\n";
	$string .= "\t),\n";
	$string .= "\t\"idle-timeout\" => 3,\n";
	$string .= "\t\"strip-request-uri\" => \"{$appurl}/\"\n";
	$string .= "\t))\n";
	$string .= ")\n";

	if (!$app->isOn('accessible_directly')) {
		$string .= "}\n\n";
	}

	return $string;
}

function updateMainConfFile()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$init_file = "/home/lighttpd/conf/defaults/init.conf";

	lfile_put_contents($init_file, '');

	$stats_file = "/home/lighttpd/conf/defaults/stats.conf";

	lfile_put_contents($stats_file, '');

	if (file_exists("/etc/lighttpd/conf/kloxo")) {
		// MR -- delete old structure
		exec("rm -rf /etc/lighttpd/conf/kloxo");
	}
}

function enablePhp()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$string = null;

	if (!$this->main->priv->isOn('php_flag'))  {
		return null;
	}

	if ($this->isRailsDocroot()) {
		return;
	}

	$fcgi_proc = 1;

	if ($this->main->priv->phpfcgiprocess_num > 0) {
		$fcgi_proc = $this->main->priv->phpfcgiprocess_num;
	}

	if (is_unlimited($this->main->priv->phpfcgiprocess_num)) {
		$fcgi_proc = "1";
	}

	if ($this->main->isOn('fcgi_children')) {
		$maxprocstring = "\t\t\"max-procs\" => 1,\n";
		$fcgichildstring = "\t\t\t\"PHP_FCGI_CHILDREN\" => \"{$fcgi_proc}\",\n";
	} else {
		$maxprocstring = "\t\t\"max-procs\" => {$fcgi_proc},\n";
		$fcgichildstring = "\t\t\t\"PHP_FCGI_CHILDREN\" => \"0\",\n";
	}

	$phprc = null;

	lxfile_unix_chown("/home/httpd/{$this->main->nname}", "{$this->main->username}:apache");
	lxfile_unix_chmod("/home/httpd/{$this->main->nname}", "0775");

	if (!lxfile_exists("/home/httpd/{$this->main->nname}/php.ini")) {
		lxfile_cp("/etc/php.ini", "/home/httpd/{$this->main->nname}/php.ini");
	}
	$phprc = "\t\t\t\"PHPRC\" => \"/home/httpd/{$this->main->nname}\",\n";

	if (!lxfile_exists("/var/tmp/lighttpd")) {
		lxfile_mkdir("/var/tmp/lighttpd");
		lxfile_unix_chown("/var/tmp/lighttpd", "apache:apache");
		lxfile_unix_chmod("/var/tmp/lighttpd", "0770");
	}

	// --- for 'disable' client
	if(!$this->main->isOn('status')) {
		$string .= "\tcgi.assign = ( \".php\" => \"/home/httpd/nobody.sh\" )\n\n";
		return $string;
	}

	if ($this->main->priv->isOn('phpfcgi_flag')) {
		$uid = os_get_uid_from_user($this->main->username);
		$gid = os_get_gid_from_user($this->main->username);
		$string .= "\tfastcgi.server = ( \".php\" => ";
		$string .= "(( \"socket\" => \"/var/tmp/lighttpd/php.socket.{$this->main->nname}.\" + var.PID,\n";
		$string .= "\t\t\"bin-path\" => \"/usr/bin/lxsuexec\",\n";
		$string .= "\t\t\"min-procs\" => 0,\n";
		$string .= $maxprocstring;
		$string .= "\t\t\"bin-environment\" => (\n";
		$string .= "\t\t\t\"MUID\" => \"{$uid}\",\n";
		$string .= "\t\t\t\"GID\" => \"{$gid}\",\n";
		$string .= $phprc;
		$string .= "\t\t\t\"TARGET\" => \"/usr/bin/php-cgi\",\n";
		$string .= "\t\t\t\"NON_RESIDENT\" => \"0\",\n";
		$string .= $fcgichildstring;
		$string .= "\t\t\t\"PHP_FCGI_MAX_REQUESTS\" => \"100000000\" ),\n";
		$string .= "\t\t\"max-load-per-proc\" => 1000,\n";
		$string .= "\t\t\"idle-timeout\" => 3 ";
		$string .= "))\n";
		$string .= "\t)\n\n";
	} else {
		$string .= "\tcgi.assign = ( \".php\" => \"/home/httpd/{$this->main->nname}/phpsuexec.sh\", \n";
		$string .= "\t\t\".pl\" => \"/home/httpd/{$this->main->nname}/perlsuexec.sh\" )\n\n";
	}

	return $string;
}

function delDomain()
{
	global $gbl, $sgbl, $login, $ghtml; 
	
	// Very important. If the nname is null, then the 'rm -rf' command will delete all the domains.
	// So please be carefule here. Must find a better way to delete stuff.
	if (!$this->main->nname) {
		return;
	}

	// MR -- don't need updateMainConfFile() for new structure but directly delete domain config file
//	$this->updateMainConfFile();

	$plist = array('domains', 'redirects', 'wildcards', 'exclusive');
	$bpath = "/home/lighttpd/conf";

	foreach($plist as $k => $v) {
		lxfile_rm("{$bpath}/{$v}/{$this->main->nname}.conf");
	}

	$this->main->deleteDir();

	self::createSSlConf($this->main->__var_ipssllist, $this->main->__var_domainipaddress);
}

function getServerIp()
{
	global $gbl, $sgbl, $login, $ghtml; 

	foreach($this->main->__var_domainipaddress as $ip => $dom) {
		if ($dom === $this->main->nname) {
			return true;
		}
	}

	return false;
}

function clearDomainIpAddress()
{
	$iplist = os_get_allips();

	foreach($this->main->__var_domainipaddress as $ip => $dom) {
		if (!array_search_bool($ip, $iplist)) {
			unset($this->main->__var_domainipaddress[$ip]);
		}
	}
}

function createConffile()
{
	global $gbl, $sgbl, $login, $ghtml; 

	global $global_shell_error;

	//dprintr($this->main->__old_priv);

	$this->clearDomainIpAddress();

	$web_home   = $sgbl->__path_httpd_root ;
	$domainname = $this->main->nname;
	$log_path   = "{$web_home}/{$this->main->nname}/stats";

	$v_file = "/home/lighttpd/conf/wildcards/{$domainname}.conf";
	$string = "### No * (wildcards) for '{$domainname}' ###\n\n\n";
	lfile_put_contents($v_file, $string);

	// must set here to prepend if server_alias_a empty
	$count = 1;

	foreach($this->main->server_alias_a as $val) {
		// issue 674 - wildcard and subdomain problem
		if ($val->nname === '*') { 
			$count = 2;
			break;
		}
	}

	for ($c = 0 ; $c < $count ; $c++) {
		$string = null;

		$dirp = $this->main->__var_dirprotect;

		if ($c === 1) {
			// issue 674 - wildcard and subdomain problem
			// not include content of $this->createServerAliasLine() because make too long
			// that mean overlapp declare
			// REMARK -- near impossible for wildcards but still access to defaults page
			// (cp/defaults/disable). So make a simple way.
			$string .= "\$HTTP[\"host\"] =~ \"{$domainname}\" {\n";
		}
		else {
			$line = $this->createServerAliasLine();
			$string .= "\$HTTP[\"host\"] =~ \"{$line}\" {\n";
		}

		$string .= $this->syncToPort("80", "www");
		$string .= $this->middlepart($domainname, $dirp); 
		$string .= "}\n\n";

		lxfile_mkdir($this->main->getFullDocRoot());

		if ($this->getServerIp()) {
			foreach($this->main->__var_domainipaddress as $ip => $dom) {
				if ($this->main->nname !== $dom) { continue ; }

				foreach($this->main->__var_ipssllist as $iip) {
					if ($iip['ipaddr'] === $ip) {
						break;
					}
				}
				if ($c === 0) {
					$string .= "\$SERVER[\"socket\"] == \"$ip:443\" {\n";
					$string .= $this->syncToPort("443", "www");
					$string .= $this->middlepart($domainname, $dirp); 
					$string .= $this->getSslCert($iip);
					$string .= "}\n\n";
				}
			}

			$exclusiveip = true;

		}

		if ($c === 1) {
			$v_file = "/home/lighttpd/conf/wildcards/{$this->main->nname}.conf";
			lfile_put_contents($v_file, $string);
		}
		else {
//			$v_file = "/home/lighttpd/conf/domains/{$this->main->nname}.conf";

			if ($exclusiveip) {
				$v_file = "/home/lighttpd/conf/exclusive/{$domainname}.conf";

				$v2_file = "/home/lighttpd/conf/domains/{$domainname}.conf";
				lfile_put_contents($v2_file, "### Have exclusive ip for '{$domainname}' ###\n\n\n");
			}
			else {
				$v_file = "/home/lighttpd/conf/domains/{$domainname}.conf";

				$v2_file = "/home/lighttpd/conf/exclusive/{$domainname}.conf";
				lfile_put_contents($v2_file, "### No exclusive ip for '{$domainname}' ###\n\n\n");
			}

			$mmaillist = $this->main->__var_mmaillist;

			foreach($mmaillist as $m) {
				if ($m['nname'] === $domainname) {
					$list = $m;
					break;
				}
			}

			// --- for the first time domain create
			if (!isset($list)) {
				$list = array('nname' => $domainname, 'parent_clname' => 'domain-'.$domainname, 'webmailprog' => '', 'webmail_url' => '', 'remotelocalflag' => 'local');
			}

			if($this->main->isOn('status')) {
				$string .= self::getCreateWebmail(array($list));
			}
			else {
				$string .= self::getCreateWebmail(array($list), $isdisabled = true);
			}

			lfile_put_contents($v_file, $string);

			// --- info for exclusive ip
//			$string = "### No declare 'exclusive ip' config for '{$domainname}' ###\n\n\n";
//			$v_file = "/home/lighttpd/conf/exclusive/{$domainname}.conf";

//			lfile_put_contents($v_file, $string);		

			$this->setAddon();
		}
	}
	
	createRestartFile("lighttpd");
}

function setAddon()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$string = null;

	$vaddonlist = $this->main->__var_addonlist;

	foreach((array) $vaddonlist as $v) {
		if ($v->ttype === 'redirect') {
			$string .= "\$HTTP[\"host\"] =~ \"^(www.)?{$v->nname}\" {\n\n";
			$dst = "{$this->main->nname}/{$v->destinationdir}";
			$dst = remove_extra_slash($dst);
			//$dst = trim($dst, "/");
			$string .= "\turl.redirect = ( \"/\" => \"http://{$dst}\" )\n\n";
			$string .= "}\n\n";
		}

		$domto = str_replace("domain-","", $v->parent_clname);
		$rlflag = ($v->mail_flag === 'on') ? 'remote' : 'local';

		$list = array('nname' => $v->nname, 'parent_clname' => $v->parent_clname, 'webmailprog' => '', 'webmail_url' => 'webmail.'.$domto, 'remotelocalflag' => $rlflag);

		if($this->main->isOn('status')) {
			$string .= self::getCreateWebmail(array($list));
		}
		else {
			$string .= self::getCreateWebmail(array($list), $isdisabled = true);
		}
	}

	if ($this->main->isOn('force_www_redirect')) {
		$string .= "\$HTTP[\"host\"] =~ \"^{$this->main->nname}$\" {\n\n";
		$string .= "\turl.redirect = ( \"^/(.*)\" => \"http://www.{$this->main->nname}/\$1\" )\n\n";
		$string .= "}\n\n";
	}

	if (!$string) {
		$string = "### No domain(s) redirect to '{$this->main->nname}' ###\n\n";
	}

	$v_file = "/home/lighttpd/conf/redirects/{$this->main->nname}.conf";

	lfile_put_contents($v_file, $string);

}

function getBlockIP()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$t = trimSpaces($this->main->text_blockip);
	$t = trim($t);

	if (!$t) { return; }

	$t = str_replace(".*", "", $t);
	$t = str_replace(" ", "|", $t);

	$string  = "\$HTTP[\"remoteip\"] =~ \"{$t}\" {\n";
	$string .= "url.access-deny = ( \"\" )\n";
	$string .= "}\n";

	return $string;
}

static function createSSlConf($iplist, $domainiplist)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$string = null;
	$alliplist = os_get_allips();

	foreach($iplist as $ip) {

		if (!array_search_bool($ip['ipaddr'], $alliplist)) { continue; }

		// issue related to delete 'exclusive ip/domain'
		if (isset($domainiplist[$ip['ipaddr']])) {
			$v = $domainiplist[$ip['ipaddr']];
			if (($v !== '') && ($v !== '--Disabled--')) {
				continue;
			}
		}

		$ssl_cert = null;
		$ssl_cert = sslcert::getSslCertnameFromIP($ip['nname']);
		$certificatef = "{$sgbl->__path_ssl_root}/{$ssl_cert}.crt";
		$keyfile = "{$sgbl->__path_ssl_root}/{$ssl_cert}.key";
		$pemfile = "{$sgbl->__path_ssl_root}/{$ssl_cert}.pem";
		$cafile = "{$sgbl->__path_ssl_root}/{$ssl_cert}.ca";

		sslcert::checkAndThrow(lfile_get_contents($certificatef), lfile_get_contents($keyfile), $ssl_cert);

		if (!lxfile_exists($pemfile)) {
			$c = lfile_get_contents($certificatef);
			$k = lfile_get_contents($keyfile);
			lfile_put_contents($pemfile, "{$c}\n{$k}");
		}

		$string .= "\$SERVER[\"socket\"] == \"{$ip['ipaddr']}:443\" {\n\n";
		$string .= "\tssl.engine = \"enable\"\n";
		$string .= "\tssl.pemfile = \"$pemfile\"\n";
		$string .= "\tssl.ca-file = \"$cafile\"\n\n";
		$string .= "}\n\n";
	}

	// issue #725, #760 - ssl.conf must be the first file in listing
	// so change name from ssl.conf to __ssl.conf

	system("rm -rf /home/lighttpd/conf/defaults/ssl.conf");
	$sslfile = "/home/lighttpd/conf/defaults/__ssl.conf";

	lfile_put_contents($sslfile, $string);
}

function getSslCert($ip)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$string = null;

	$ssl_cert = null;
	$ssl_cert = sslcert::getSslCertnameFromIP($ip['nname']);
	$certificatef = "{$sgbl->__path_ssl_root}/{$ssl_cert}.crt";
	$keyfile = "{$sgbl->__path_ssl_root}/{$ssl_cert}.key";
	$pemfile = "{$sgbl->__path_ssl_root}/{$ssl_cert}.pem";
	$cafile = "{$sgbl->__path_ssl_root}/{$ssl_cert}.ca";

	sslcert::checkAndThrow(lfile_get_contents($certificatef), lfile_get_contents($keyfile), $ssl_cert);

	if (!lxfile_exists($pemfile)) {
		$c = lfile_get_contents($certificatef);
		$k = lfile_get_contents($keyfile);
		lfile_put_contents($pemfile, "{$c}\n{$k}");
	}

	$string .= "\tssl.engine = \"enable\"\n";
	$string .= "\tssl.pemfile = \"{$pemfile}\"\n";
	$string .= "\tssl.ca-file = \"{$cafile}\"\n\n";

	return $string;
}

function createShowAlist(&$alist, $subaction = null)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$gen = $login->getObject('general')->generalmisc_b;

	return $alist;
}

function middlepart($domain, $dirp) {

	global $gbl, $sgbl, $login, $ghtml; 

	$string = null;

	if($this->main->isOn('status')) {
		foreach((array) $this->main->__var_railspp as $r) {
			if (!$r->isDeleted()) {
				$string .= $this->getRailsConf($r);
			}
		}
	}

	foreach($this->main->customerror_b as $k => $v) {
		if (csb($k, "url_") && $v) {
			$num = strfrom($k, "url_");
			if (csb($v, "http:/")) {
				$nv = $v;
			} else {
				$nv = remove_extra_slash("/$v");
			}
			if ($num !== "404") {
				continue;
			}
			if (!$this->isRailsDocroot()) {
				$string .= "\tserver.error-handler-{$num} = \"{$nv}\"\n";
			}
		}
	}

	$string .= $this->enablePhp();

	if (isset($this->main->webmisc_b)) {
		if ($this->main->webmisc_b->isOn('dirindex')) {
			$string .= "\tdir-listing.activate = \"enable\"\n";
		}
	}

	if ($this->main->stats_password) {
		$string .= $this->getDirprotectCore("stats", "/stats", "__stats");
	}

	$string .= $this->getDirIndexCore("/stats");
	$string .= $this->getDirprotect();

	return $string;
}

function getDirIndexCore($dir)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$string = null;

	$dir = remove_extra_slash("/{$dir}");
	$string .= "\t\$HTTP[\"url\"] =~ \"^{$dir}\" {\n";
	$string .= "\t\tdir-listing.activate = \"enable\"\n\t}\n\n";

	return $string;
}

function getDirprotect()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$string = null;
	foreach((array) $this->main->__var_dirprotect as $prot) {
		if (!$prot->isOn('status') || $prot->isDeleted()) {
			continue;
		}
		$string .= $this->getDirprotectCore($prot->authname, $prot->path, $prot->getFileName());

	}

	return $string;
}

function getDirprotectCore($authname, $path, $file)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$path = remove_extra_slash("/{$path}");
	$end = null;

	if ($path !== "/") { $end = "[/$]"; }

	$string = null;

	$string .= "\$HTTP[\"url\"] =~ \"^{$path}{$end}\" {\n";
	$string .= "auth.backend = \"htpasswd\"\n";
	$string .= "auth.backend.htpasswd.userfile = \"{$sgbl->__path_httpd_root}/{$this->main->nname}/__dirprotect/{$file}\"\n";
	$string .= "auth.require = ( \"{$path}\" => (\n";
	$string .= "\"method\" => \"basic\",\n";
	$string .= "\"realm\" => \"{$authname}\",\n";
	$string .= "\"require\" => \"valid-user\"\n";
	$string .= "))\n}\n";

	return $string;
}

function isRailsDocroot()
{
	global $gbl, $sgbl, $login, $ghtml;

	$app = $this->main->__var_railspp;

	foreach((array)$app as $k) {
		if ($k->isOn('accessible_directly')) {
			return true;
		}
	}

	return false;
}

function getDocumentRoot($subweb)
{
	global $gbl, $sgbl, $login, $ghtml;

	$base_root = $sgbl->__path_httpd_root;
	$web_home = $sgbl->__path_httpd_root;

	$path = "{$this->main->getFullDocRoot()}/";

	$domname = $this->main->nname;

	// --- issue #698 - Always redirect to /awstats when access to stats.gif
	// all redirect change to ^(/x/|/x$) formula for resolve this issue

	$string = null;
	$string .= "\talias.url  = ( \"/__kloxo\" => \"/home/{$this->main->customer_name}/kloxoscript\" )\n\n";
	$string .= "\turl.redirect  = ( \"^(/webmail/|/webmail\$)\" => \"http://webmail.{$domname}\" )\n";

	if ($this->main->nname !== 'lxlabs.com') {
		$string .= "\turl.redirect += ( \"^(/kloxo/|/kloxo\$)\" => \"https://cp.{$domname}:{$this->main->__var_sslport}\" )\n";
		$string .= "\turl.redirect += ( \"^(/kloxononssl/|/kloxononssl\$)\" => \"http://cp.{$domname}:{$this->main->__var_nonsslport}\" )\n";
	}

	if ($this->main->__var_statsprog === 'awstats') {
		$string .= "\turl.redirect += ( \"^(/stats/|/stats\$)\" => \"http://{$domname}/awstats/awstats.pl?config={$domname}\" )\n";
	} else {
		$string .= "\talias.url += ( \"^(/stats/|/stats\$)\" => \"{$sgbl->__path_httpd_root}/{$domname}/webstats\" )\n";
	}

	$string .= "\n";

	if($this->main->isOn('status')) {
		if (!$this->isRailsDocroot()) {
			$string .= "\tserver.document-root = \"{$path}\"\n";
		}
	} else {
		if ($this->main->__var_disable_url) {
			$url = add_http_if_not_exist($this->main->__var_disable_url);
			$string .= "\turl.redirect += ( \"/\" => \"{$url}\" )\n";
		} else {
			$disableurl = "/home/kloxo/httpd/disable/";
			$string .= "\tserver.document-root = \"{$disableurl}\"\n";
		}
	}

	$string .= "\n";

	return $string;
}

function hotlink_protection()
{
	global $gbl, $sgbl, $login, $ghtml;

	if (!$this->main->isOn('hotlink_flag')) {
		return null;
	}

	$string = null;

	$allowed_domain_string = $this->main->text_hotlink_allowed;
	$allowed_domain_string = trim($allowed_domain_string);
	$allowed_domain_string = str_replace("\r", "", $allowed_domain_string);
	$allowed_domain_string = str_replace("\n", "|", $allowed_domain_string);
	
	if ($allowed_domain_string) { 
		$allowed_domain_string .= "|{$this->main->nname}";
	} else { 
		$allowed_domain_string .= "{$this->main->nname}"; 
	}

	$ht = trim($this->main->hotlink_redirect, "/");
	$ht = "/{$ht}";

	$string .= "\n\n";
	$string .= "\$HTTP[\"referer\"] !~ \"^($|https?://(.*\.|)({$allowed_domain_string}))\" {\n";
	$string .= "url.rewrite = ( \"(?i)(/.*\.(jpe?g|png|gif|jpg|rar|pdf))\$\" =>\n";
	$string .= "\"{$ht}\" )\n";
	$string .= "}\n\n";

	return $string;
}

function getIndexFileOrder()
{
	global $gbl, $sgbl, $login, $ghtml;

	if ($this->main->indexfile_list) {
		$list = $this->main->indexfile_list;
	} else {
		$list = $this->main->__var_index_list;
	}

	if (!$list) { return; }

	$string = implode("\", \"", $list);
	$string = "\tindex-file.names = ( \"{$string}\" )\n\n";

	return $string;
}

function syncToPort($port, $subweb)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$web_home = $sgbl->__path_httpd_root ;
	$base_root = $sgbl->__path_httpd_root;
	$domainname = $this->main->nname;
	$user_home = "{$this->main->getFullDocRoot()}/";
	$log_path = "{$web_home}/{$this->main->nname}/stats"; 
	$cust_log = "{$log_path}/{$this->main->nname}-custom_log"; 
	$err_log = "{$log_path}/{$this->main->nname}-error_log";

	$string = null;

	$string .= $this->hotlink_protection();
	$string .= $this->getBlockIP();

	$string .= $this->main->text_lighty_rewrite;
	$string .= "\n";

	$domname = $this->main->nname;

	$string .= $this->getDocumentRoot($subweb);

	$string .= $this->getIndexFileOrder();

	// Hack.. This is done so that others can use '+' without any issue.

	$string .= "\talias.url += ( \"/awstatsicons\" => \"/home/kloxo/httpd/awstats/wwwroot/icon/\" )\n";
	$string .= "\talias.url += ( \"/awstatscss\" => \"/home/kloxo/httpd/awstats/wwwroot/css/\" )\n";

	$string .= $this->getAwstatsString();

	if ($this->main->priv->isOn('cgi_flag')) {
		$string .= $this->getCgiString();
	}

	foreach((array) $this->main->redirect_a as $red) {
		$rednname = remove_extra_slash("/{$red->nname}");

		if ($red->ttype === 'local') {
			$string .= "\talias.url += ( \"{$rednname}\" => \"{$user_home}/{$red->redirect}\" )\n";
		} else {
			if (!redirect_a::checkForPort($port, $red->httporssl)) { continue; }

			$string .= "\turl.redirect += ( \".*{$rednname}\" => \"{$red->redirect}\" )\n";
		}
	}

	$string .= "\taccesslog.filename = \"{$cust_log}\"\n";
	$string .= "\tserver.errorlog = \"{$err_log}\"\n\n";

	return $string;
}

function getCgiString()
{
	global $gbl, $sgbl, $login, $ghtml; 
	$web_home = $sgbl->__path_httpd_root ;
	$string = null;
	$string .= "\talias.url += ( \"/cgi-bin\" => \"{$this->main->getFullDocRoot()}/cgi-bin/\" )\n\n"; 
	$string .= "\t\$HTTP[\"url\"] =~ \"^/cgi-bin\" {\n";
	$string .= "\t\tcgi.assign = ( \"\" => \"{$sgbl->__path_httpd_root}/{$this->main->nname}/shsuexec.sh\" )\n\t}\n\n";

	return $string;
}

function getAwstatsString()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$web_home = $sgbl->__path_httpd_root ;
	$string = null;
	$string .= "\talias.url += ( \"/awstats/\" => \"{$sgbl->__path_kloxo_httpd_root}/awstats/wwwroot/cgi-bin/\" )\n\n";
	$string .= "\t\$HTTP[\"url\"] =~ \"^/awstats\" {\n";
	$string .= "\t\tcgi.assign = ( \".pl\" => \"{$sgbl->__path_httpd_root}/{$this->main->nname}/perlsuexec.sh\" )\n\t}\n\n";

	if ($this->main->stats_password) {
		$string .= $this->getDirprotectCore("Awstats", "/awstats", "__stats");
	}

	web::createstatsConf($this->main->nname, $this->main->stats_username, $this->main->stats_password);

	return $string;
}

function createSuexec()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$string = null;

	$uid = os_get_uid_from_user($this->main->username);
	$gid = os_get_gid_from_user($this->main->username);

	$phprc = null;
	$phprc .= "export PHPRC=/home/httpd/{$this->main->nname}\n";

	$string .= "#!/bin/sh\n";
	$string .= "### Username: {$this->main->username}\n";
	$string .= "export MUID={$uid}\n";
	$string .= "export GID={$gid}\n";
	$string .= $phprc;
	$string .= "export TARGET=<%program%>\n";
	$string .= "export NON_RESIDENT=1\n";
	$string .= "exec lxsuexec \$*\n";

	$st = str_replace("<%program%>", "/usr/bin/php-cgi", $string);
	lfile_put_contents("{$sgbl->__path_httpd_root}/{$this->main->nname}/phpsuexec.sh", $st);
	$st = str_replace("<%program%>", "/usr/bin/lxexec", $string);
	lfile_put_contents("{$sgbl->__path_httpd_root}/{$this->main->nname}/shsuexec.sh", $st);

	$st = str_replace("<%program%>", "/usr/bin/perl", $string);
	lfile_put_contents("{$sgbl->__path_httpd_root}/{$this->main->nname}/perlsuexec.sh", $st);

	lxfile_unix_chmod("{$sgbl->__path_httpd_root}/{$this->main->nname}/shsuexec.sh", "0755");
	lxfile_unix_chmod("{$sgbl->__path_httpd_root}/{$this->main->nname}/phpsuexec.sh", "0755");
	lxfile_unix_chmod("{$sgbl->__path_httpd_root}/{$this->main->nname}/perlsuexec.sh", "0755");
}

function createServerAliasLine()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$list = get_namelist_from_objectlist($this->main->server_alias_a);

	$iplist = null;
	foreach($this->main->__var_domainipaddress as $ip => $dom) {
		if ($dom === $this->main->nname) {
			$iplist[] = $ip;
		}
	}

	if ($list) foreach($list as &$__l) {
		$__l = "$__l.{$this->main->nname}";
	}

	if ($this->main->isOn('force_www_redirect')) {
		$list = lx_merge_good(array("www.{$this->main->nname}"), $list);
	} else {
		$list = lx_merge_good(array("www.{$this->main->nname}", $this->main->nname), $list);
	}

	foreach((array) $this->main->__var_addonlist as $d) {
		// forget this if?
		if ($d->ttype === 'redirect') {
			continue;
		}

		$list[] = $d->nname;
		$list[] = "www.{$d->nname}";
	}

	$list = lx_array_merge(array($list, $iplist));

	$string = implode("|", $list);

	// issue 674 - wildcard and subdomain problem
	// remove for * (wildcards)
	$string = str_replace("|*.{$this->main->nname}", "", $string);

	return "^($string)";
}

function addDomain()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$this->main->createDir();
	$this->createConffile();
//	$this->updateMainConfFile();
	$this->createSuexec();
	$this->main->createPhpInfo();
	
	self::createSSlConf($this->main->__var_ipssllist, $this->main->__var_domainipaddress);
}

static function getCreateWebmail($list, $isdisabled = null)
{
	global $gbl, $sgbl, $login, $ghtml; 

	foreach($list as $l) {
		$webdata = null;

		$rlflag = (!isset($l['remotelocalflag'])) ? 'local' : $l['remotelocalflag'];


		$prog = (!isset($l['webmailprog']) || ($l['webmailprog'] === '--system-default--')) ? "" : $l['webmailprog'];

		if ((!$prog) && ($rlflag !== 'remote') && (!$isdisabled)) {
			$webdata .= "### 'webmail.{$l['nname']}' handled by ../webmails/webmail.conf ###\n\n";
			continue;
		}

		$webdata .= "\$HTTP[\"host\"] =~ \"^webmail.{$l['nname']}\" { \n\n";
		if ($l['remotelocalflag'] === 'remote') {
			$l['webmail_url'] = add_http_if_not_exist($l['webmail_url']);
			$webdata .= "\turl.redirect = ( \"/\" =>  \"{$l['webmail_url']}\")\n\n";
		} else {
			if ($isdisabled) {
				$webdata .= "\tserver.document-root = \"{$sgbl->__path_kloxo_httpd_root}/disable/\"\n";
			} else {
				$prog = ($l['webmailprog'] === '--chooser--') ? "" : $l['webmailprog'];
				if ($prog) {
					$webdata .= "\tserver.document-root = \"{$sgbl->__path_kloxo_httpd_root}/webmail/{$prog}/\"\n\n";
				}
				else {
					$webdata .= "\tserver.document-root = \"{$sgbl->__path_kloxo_httpd_root}/webmail/\"\n\n";
				}
			}

			$webdata .= "\tserver.errorlog = \"/home/kloxo/httpd/lighttpd/error.log\"\n";
			$webdata .= "\tcgi.assign = ( \".php\" => \"/home/httpd/nobody.sh\" )\n\n";
		}
		$webdata .= "}\n\n";

	}

	return $webdata;
}

static function createWebDefaultConfig()
{
	global $gbl, $sgbl, $login, $ghtml; 

	self::createCpConfig();
	self::createWebmailConfig();
	
	createRestartFile("lighttpd");
}

static function createWebmailConfig($iplist = null)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$webfile = "/home/lighttpd/conf/webmails/webmail.conf";

	$webmaildef = $login->getObject('general')->generalmisc_b->webmail_system_default;

	if (($webmaildef === '--chooser--') || (!isset($webmaildef))) {
		$webmaildefpath = '';
	}
	else {
		$webmaildefpath = "{$webmaildef}/";
	}

	$webdata = null;

	$webdata .= "\$HTTP[\"host\"] =~ \"^webmail.*\" { \n\n";
	$webdata .= "\tserver.document-root = \"{$sgbl->__path_kloxo_httpd_root}/webmail/{$webmaildefpath}\"\n";
	$webdata .= "\tserver.errorlog = \"/home/kloxo/httpd/lighttpd/error.log\"\n";
	$webdata .= "\tcgi.assign = ( \".php\" => \"/home/httpd/nobody.sh\" )\n\n";
	$webdata .= "}\n\n\n";  

	lfile_put_contents($webfile, $webdata);
}

static function fixErrorLog($name)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$file = "/home/kloxo/httpd/lighttpd/error.log";
	$cmd = "grep -i {$name} {$file} > /home/httpd/{$name}/stats/{$name}-error_log";
	log_shell($cmd);
	system($cmd);

	$size = lxfile_size($file);

	if ($size > 50 * 1024 * 1024) {
		$nfile = getNotexistingFile(dirname($file), $file);
		lxfile_mv($file, $nfile);
		createRestartFile("lighttpd");
	}
}

static function fixErrorLogbad($list)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$file = "/home/kloxo/httpd/lighttpd/error.log";
	$fp = lfopen($file);

	if (!$fp) {
		return;
	}

	while (!feof($fp)) {
		$s = fgets($fp);
		foreach($list as $l) {
			if (csa($s, $l)) {
				$out[$l] .= $s;
			}
		}
	}

	foreach($out as $k => $v) {
		lfile_put_contents("/home/httpd/{$k}/stats/{$k}-error_log", $v, FILE_APPEND);
	}

	fclose($fp);

	$fp = getNotexistingFile(dirname($file), $file);

	lxfile_mv($file, $nfile);
}

function dbactionAdd()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$this->addDomain();
	$this->main->doStatsPageProtection();
}

function dbactionDelete()
{
	global $gbl, $sgbl, $login, $ghtml; 

	lunlink("/home/lighttpd/conf/domains/{$this->main->nname}.conf");

	$this->delDomain();
}

function dosyncToSystemPost()
{
	global $gbl, $sgbl, $login, $ghtml; 

	createRestartFile("lighttpd");
}

function fullUpdate()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$this->createConffile();
	$this->createSuexec();
//	$this->updateMainConfFile();

	self::createSSlConf($this->main->__var_ipssllist, $this->main->__var_domainipaddress);

//	self::createWebDefaultConfig();

	web::createstatsConf($this->main->nname, $this->main->stats_username, $this->main->stats_password);

	$log_path = "/home/httpd/{$this->main->nname}/stats";
	lxfile_unix_chown_rec($log_path, "{$this->main->username}:apache");
	lxfile_unix_chmod_rec($log_path, "770");

	$this->main->createPhpInfo();

	lxfile_unix_chown("__path_httpd_root/{$this->main->nname}", "{$this->main->username}:apache");
	lxfile_unix_chmod("__path_httpd_root/{$this->main->nname}", "0755");
	lxfile_unix_chmod("{$this->main->getFullDocRoot()}", "0755");
}

static function createCpConfig()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$list = array("default" => "_default.conf", "cp" => "cp_config.conf", "disable" => "disable.conf");

	foreach($list as $config => $file) {
		$webdata  = null;
		$webdata .= "\$HTTP[\"host\"] =~ \"^{$config}.*\" { \n\n";
		$webdata .= "\tserver.document-root = \"{$sgbl->__path_kloxo_httpd_root}/{$config}/\"\n";
		$webdata .= "\tserver.errorlog = \"/home/lighttpd/logs/error.log\"\n";
		$webdata .= "\tcgi.assign = ( \".php\" => \"/home/httpd/nobody.sh\" )\n\n";
		$webdata .= "}\n\n\n";

		$fullfile = "/home/lighttpd/conf/defaults/{$file}";

		lfile_put_contents($fullfile, $webdata);

		system("chown lxlabs:lxlabs {$fullfile}");
	}
}

function dbactionUpdate($subaction)
{
	global $gbl, $sgbl, $login, $ghtml; 

	if (!$this->main->customer_name) {
		log_log("critical", "Lack customername for web: {$this->main->nname}");
		return;
	}

	switch($subaction) {

		case "full_update":
			$this->fullUpdate();
			$this->main->doStatsPageProtection();
			break;

		case "changeowner":
			$this->main->webChangeOwner();
			$this->createConffile();
			$this->createSuexec();
			break;

		case "fixipdomain":
			$this->createConffile();
			$this->updateMainConfFile();
			self::createSSlConf($this->main->__var_ipssllist, $this->main->__var_domainipaddress);
			break;

		case "addondomain":
			$this->createConffile();
			break;

		case "phpconfig":
		case "add_delete_dirprotect":
		case "extra_tag" : 
		case "dirindex":
		case "add_dirprotect" : 
		case "custom_error":
		case "lighty_rewrite":
		case "blockip";
		case "docroot":
		case "ipaddress": 
		case "add_redirect_a":
		case "delete_redirect_a":
		case "delete_redirect_a":
		case "add_webindexdir_a":
		case "delete_webindexdir_a":
		case "add_server_alias_a" : 
		case "delete_server_alias_a" : 
		case "configure_misc":
		case "fcgi_config":
		case "railsconf":
			$this->createConffile();
			$this->createSuexec();
			break;

		case "toggle_status" : 
			$this->createConffile();
			break;

		case "enable_phpfcgi_flag":
		case "enable_php_flag":
		case "change_phpfcgiprocess_num":
		case "enable_cgi_flag":
		case "enable_inc_flag":
		case "enable_ssl_flag" : 
			$this->createConffile();
			$this->updateMainConfFile();
			break;

		case "enable_php_manage_flag":
			$this->createConffile();
			$this->createSuexec();
			break;

		case "stats_protect":
			$this->main->doStatsPageProtection();
			$this->createConffile();
			break;

		case "hotlink_protection":
		case "permalink":
			$this->createConffile();
			break;

		case "graph_webtraffic":
			return rrd_graph_single("webtraffic", $this->main->nname, $this->main->rrdtime);
			break;

		case "run_stats":
			$this->main->runStats();
			break;

		case "static_config_update":
			self::createCpConfig();
			self::createWebDefaultConfig();
			$this->updateMainConfFile();
			break;
	}
}

function getDav()
{
	global $gbl, $sgbl, $login, $ghtml; 

	$string = null;

	$bdir = "/home/httpd/{$this->main->nname}/__webdav";
	lxfile_mkdir($bdir);

	foreach($this->main->__var_davuser as $k => $v) {
		$file = get_file_from_path($k);
		$dbf = "/tmp/{$file}.db";
		$file = "$bdir/{$file}";
		lxfile_touch($file);
		$string .= "\$HTTP[\"url\"] =~ \"^{$k}(\$|/)\" {\n";
		$string .= "webdav.activate = \"enable\"\n";
		$string .= "webdav.is-readonly = \"disable\"\n";
		$string .= "auth.backend = \"htpasswd\"\n";
		$string .= "auth.backend.htpasswd.userfile = \"{$file}\"\n";
		$string .= "webdav.sqlite-db-name = \"{$dbf}\"\n";
		$string .= "auth.require = ( \"\" => ( \"method\" => \"basic\",\n";
		$string .= "\"realm\" => \"webdav\",\n";
		$string .= "\"require\" => \"valid-user\" ) )\n";
		$string .= "}\n";
	}

	return $string;
}

function do_backup()
{
	global $gbl, $sgbl, $login, $ghtml; 

	return $this->main->do_backup();
}

function do_restore($docd)
{
	global $gbl, $sgbl, $login, $ghtml; 

	$name = $this->main->nname;
	$fullpath = "$sgbl->__path_customer_root/{$this->main->customer_name}/$name/";

	$this->main->do_restore($docd);

	lxfile_unix_chown_rec($fullpath, $this->main->username);
}

}
