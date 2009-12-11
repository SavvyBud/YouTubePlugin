#!/usr/bin/php
<?php

/*
	http://code.google.com/apis/youtube/2.0/developers_guide_php.html#code_printVideoEntry
	Author: Vivek Srivastav
	Date: 09/20/09
	Version: 0.1
	
	Version: 0.2
	Date: 11/27/09
	Adding playlist support
	Download file using cclive in mp4 format to play on iPod
*/
$LOCAL_EXEC_CMD         = '/usr/bin/mplayer -fs -zoom -really-quiet';


class YouTubeFav 
{
	public $DOWNLOAD_FILES_DIR="/.mythtv/youtube";
	public $pl = NULL;
	public $CFG_FILE=".youTubeCfg";
	public $PLUGIN_FILE="youTube_plugin.xml";
	public $pluginFileHandle = NULL;
	public $cfg;

	public function check_dir(){
   	if(!is_dir($this->DOWNLOAD_FILES_DIR)) {
      	if(mkdir($this->DOWNLOAD_FILES_DIR,0777,true)) {
         	if($DEBUG) { error_log("The directory \"$DOWNLOAD_FILES_DIR\" didn't exist, but we created it for you...\n"); }
      	} else {
         	print "\t<button>\n";
         	print "\t\t<type>TV_DELETE</type>\n";
         	print "\t\t<text>ERROR: download directory doesn't exist!</text>\n";
         	print "\t\t<action>EXEC xmessage -center -timeout 10 The directory: $DOWNLOAD_FILES_DIR; doesn't exist</action>\n";
         	print "\t</button>\n\n";
         	print "</mythmenu>\n";
         	exit(1);
      	}
   	}
	}

	public function backup_files(){
      if(!is_dir($this->DOWNLOAD_FILES_DIR."/old")) {
         mkdir($this->DOWNLOAD_FILES_DIR."/old");
      }
		$cmd= "/bin/mv ".$this->DOWNLOAD_FILES_DIR."/*.mp4 ".$this->DOWNLOAD_FILES_DIR."/old 2> /dev/null\n";
		system($cmd);
	}

	public function purge_old(){
		$cmd="/bin/rm -rf ".$this->DOWNLOAD_FILES_DIR."/old";
   	system($cmd);
	}

	public function init(){
		$h = getenv("HOME");
		$this->DOWNLOAD_FILES_DIR=$h."/.mythtv/youtube";
		$p = ini_get('include_path');
		ini_set('include_path',$p.':'.$h.'/opt/ZendGdata/library');
		$p = ini_get('include_path');
		require_once('Zend/Gdata/ClientLogin.php');
		require_once('Zend/Gdata/YouTube.php');
		$this->check_dir();
		chdir($this->DOWNLOAD_FILES_DIR);
		$pluginFile = $h."/.mythtv/".$this->PLUGIN_FILE.".tmp";
		$this->pluginFileHandle = fopen($pluginFile,"w");
		date_default_timezone_set('America/New_York');
	}
	
	public function close_plugin_file(){
		fclose($this->pluginFileHandle);
		$h = getenv("HOME");
		$pluginFile = $h."/.mythtv/".$this->PLUGIN_FILE;
      system("/bin/mv -f $pluginFile.tmp $pluginFile");
	}

	public function download_video($url,$vid){
      if(file_exists($this->DOWNLOAD_FILES_DIR."/old/".$vid.".mp4")){
			print ("Video id: $vid already downloaded\n");
      	system("/bin/mv ".$this->DOWNLOAD_FILES_DIR."/old/".$vid.".mp4 ".$this->DOWNLOAD_FILES_DIR."/");
      }else{
			if($this->cfg['DOWNLOAD'] == 1){
				print ("Download video id: $vid from URL \"$url\"\n");
				if(strncmp("http",$url,4) == 0){
					$cmd="cclive -c --retry=1 -f fmt18 -F \"$vid.%s\" -gr \"(\w+)\" $url";
					system($cmd);
				} else{
					throw new Exception("Invalid URL: $url");
				}
			}
		}
	}

	public function print_header($fh,$sync){
		fprintf($fh, "<mythmenu name=\"YouTube\">\n");
		if($sync == 1) {
			fprintf($fh, "\t<button>\n");
			fprintf($fh, "\t\t<type>RSYNC</type>\n");
			fprintf($fh, "\t\t<text><![CDATA[Synch %s]]></text>\n",date('m/d/y G:i'));
			fprintf($fh, "\t\t<action><![CDATA[EXEC /home/mythtv/unofficial_plugins/gdata/yt.sh]]></action>\n");
			fprintf($fh, "\t</button>");
		}
	}
	
	public function create_playlist_menu($fh,$user, $title){
		global $LOCAL_EXEC_CMD;
		$plf = $this->DOWNLOAD_FILES_DIR."/".$title.".pl";
		$mythtvPlayerCmd="EXEC $LOCAL_EXEC_CMD -playlist ".$plf;
      fprintf($fh, "\t<button>\n");
      fprintf($fh, "\t\t<type>VIDEO_BROWSER</type>\n");
      fprintf($fh, "\t\t<text><![CDATA[Playlist $title]]></text>\n");
      fprintf($fh, "\t\t<action><![CDATA[$mythtvPlayerCmd]]></action>\n");
      fprintf($fh, "\t</button>\n\n");
	}
	
	public function print_footer($fh){
		fprintf($fh, "</mythmenu>\n");
	}

	public function print_menu($fh, $title,$vid) {
		global $LOCAL_EXEC_CMD;
		$mythtvPlayerCmd="EXEC $LOCAL_EXEC_CMD ".$this->DOWNLOAD_FILES_DIR."/".$vid;
      fprintf($fh, "\t<button>\n");
      fprintf($fh, "\t\t<type>VIDEO_BROWSER</type>\n");
      fprintf($fh, "\t\t<text><![CDATA[$title]]></text>\n");
      fprintf($fh, "\t\t<action><![CDATA[$mythtvPlayerCmd]]></action>\n");
      fprintf($fh, "\t</button>\n\n");
	}

	public function printEntireFeed($fh, $feed, $menu) {
		foreach($feed as $videoEntry){
			$vid = $videoEntry->getVideoId();
			try {
				$this->download_video($videoEntry->getVideoWatchPageUrl(), $vid);
				if($menu == 1){
					$this->print_menu($fh, $videoEntry->getVideoTitle(),$vid.".mp4");	
				}
				$this->update_playlist($vid.".mp4");
			}catch (Exception $e){
   			echo "Exception: ". $e->getMessage() . " skipping video $vid\n";
			}
		}
		try {
   		$feed = $feed->getNextFeed();
 		} catch (Zend_Gdata_App_Exception $e) {
   		//echo $e->getMessage() . "\n";
   		return;
 		}
		if($feed){
			$this->printEntireFeed($fh, $feed,$menu);
		}
	}

	public function get_favorite($yt, $user){
		$feed = $yt->getUserFavorites($user);
		$playlistfile = $user."_fav";
		$this->open_playlist($playlistfile);
		$this->printEntireFeed($this->pluginFileHandle,$feed,0);
		$this->close_playlist();
	}

	public function open_playlist($playlistfile){
		$this->close_playlist();
		$plf = $this->DOWNLOAD_FILES_DIR."/".$playlistfile.".pl";
		$this->pl = fopen($plf,"w+");
		$mythtvPlayerCmd="MENU $playlistfile.xml";
      fprintf($this->pluginFileHandle, "\t<button>\n");
      fprintf($this->pluginFileHandle, "\t\t<type>VIDEO_BROWSER</type>\n");
      fprintf($this->pluginFileHandle, "\t\t<text><![CDATA[Playlist $playlistfile]]></text>\n");
      fprintf($this->pluginFileHandle, "\t\t<action><![CDATA[$mythtvPlayerCmd]]></action>\n");
      fprintf($this->pluginFileHandle, "\t</button>\n\n");
		$cmd="sudo ln -t /usr/share/mythtv -s ".$this->DOWNLOAD_FILES_DIR."/".$playlistfile.".xml 2>&1";
		system($cmd);
	}

	public function close_playlist(){
		if($this->pl){
			fclose($this->pl);
		}
		$this->pl = NULL;
	}
	
	public function update_playlist($vid){
		if($this->pl){
			$file_entry=$this->DOWNLOAD_FILES_DIR."/".$vid."\n";
			fwrite($this->pl, $file_entry);
		}
	}
	
	public function retrieve_favorite($yt,$user){
		print "Creating favorite for user: $user ...\n";
		$feed = $yt->getUserFavorites($user);
		$title = $user . "_fav";
		$this->create_playlist($user, $title, $feed);
	}

	public function create_playlist($user,$title,$feed){
		$title = preg_replace("/\s+/","_",$title);
		$title = preg_replace("/[^\w|^\d]/","",$title);	
		print "Creating playlist: $title\n";
		$pl_xml = $this->DOWNLOAD_FILES_DIR."/".$title.".xml";
		$pl_xml_fh = fopen($pl_xml.".tmp","w");

		$this->open_playlist($title);
		$this->print_header($pl_xml_fh,0);
		$this->create_playlist_menu($pl_xml_fh,$user,$title);
		$this->printEntireFeed($pl_xml_fh,$feed,1);
		$this->print_footer($pl_xml_fh);

		fclose($pl_xml_fh);
		$cmd="/bin/mv $pl_xml.tmp $pl_xml";
		$this->close_playlist();
		system($cmd);
	}

	public function retrieve_playlists($yt,$user){
		$playlists = $yt->getPlaylistListFeed($user);
		foreach($playlists as $playlistListEntry){
			$feed = $yt->getPlaylistVideoFeed($playlistListEntry->getPlaylistVideoFeedUrl());
			$this->create_playlist($user, $playlistListEntry->title->text, $feed);
		}
	}

	public function retrieve_subscriptions($yt,$user){
		$yt->setMajorProtocolVersion(2);
		$subscriptionFeed = $yt->getSubscriptionFeed($user);
		print "count " . count($subscriptionFeed) . "\n";
		foreach($subscriptionFeed as $subscriptionEntry){
			$subscriptionType = null;
			// get the array of categories to find out what type of subscription it is
			$categories = $subscriptionEntry->getCategory();
			// examine the correct category element since there are multiple
			foreach($categories as $category) {
				if ($category->getScheme() == 'http://gdata.youtube.com/schemas/2007/subscriptiontypes.cat') {
					$subscriptionType = $category->getTerm();
				}
			}
				
			switch ($subscriptionType) {
				case 'channel':
					echo 'Subscription to channel: ' . $subscriptionEntry->getUsername()->text . "\n";
					break;
				case 'query':
					echo 'Subscription to search term: ' . $subscriptionEntry->getQueryString() . "\n";
					break;
				case 'favorites':
					echo 'Subscription to favorites of user ' . $subscriptionEntry->getUsername()->text . "\n";
					break;
				case 'playlist':
					echo 'Subscription to playlist "' . $subscriptionEntry->getPlaylistTitle() . 
					'" of user ' . $subscriptionEntry->getUsername()->text . "\n";
					break;
			}
		}
	}

	public function create_cfg_file($cfg){
		print "Creating config file: $cfg\n";
		$fh = fopen($cfg,"w");
		fwrite($fh,"# Enter YouTube accounts separated by comma.\n");
		fwrite($fh,"USERS_LIST=\n");
		fwrite($fh,"# Download videos.\n");
		fwrite($fh,"DOWNLOAD=1\n");
		fclose($fh);
	}

	public function run(){
		$this->init();
		$yt = new Zend_Gdata_YouTube();
		$comment="#";
		
		$h=getenv("HOME");
		$fn = $h ."/".$this->CFG_FILE;

		$this->backup_files();
		$this->print_header($this->pluginFileHandle,1);

		// read config
		if(file_exists($fn)){
			$fh = fopen($fn,"r");
			while ( !feof($fh) ){
				$line = fgets($fh);
				if (!ereg("^$comment", $line)) {
					if(strlen($line)){
						$pieces = explode("=", $line);
    					$option = trim($pieces[0]);
    					$value = trim($pieces[1]);
    					$this->cfg[$option] = $value;
					}
				}
			}
			fclose($fh);

			$tok = strtok($this->cfg['USERS_LIST'],",");
			while($tok != false){
				print "Processing user: $tok\n";
				$user=trim($tok);
				$this->retrieve_playlists($yt,$user);
				$this->retrieve_favorite($yt,$user);
				$this->retrieve_subscriptions($yt,$user);
				$tok = strtok(",");
			}
		}else{
			echo "~/".$this->CFG_FILE." not found. creating...\n";
			echo "Please update this file to retrieve the youTube videos\n";
			$this->create_cfg_file($fn);
			exit(1);
		}
		$this->print_footer($this->pluginFileHandle);
		$this->purge_old();
		$this->close_plugin_file();
	}
}

$a = new YouTubeFav();
$a->run();
?>

