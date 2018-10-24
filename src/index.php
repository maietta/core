<?php

/**
 * Bigfoot class
 *
 * @author Nicholas Maietta
 */
class Bigfoot extends Prefab {

	private static $instance;
	private $dbh;
	private static $auth;
	
	public $content;
	public $inner_content;
	
	public $prepared;
	public $security;
	public $meta;
	public $theme;
	public $script;
	public $internal;
	public $status;
	public $content_area;
	public $permission;
	public $html;
	
	private function __construct() {
		//$hooks = Base::instance()->get("HOOKS");
	}

	public static function CMS($config=NULL) {
		base::instance()->set("vpath", ((substr(base::instance()->get("PATH"),-1)=='/')?substr(base::instance()->get("PATH"),0,-1).'/':base::instance()->get("PATH")));
		base::instance()->set("sitelevel", ('/'. substr(substr(base::instance()->get("vpath"),1), 0, strpos(substr(base::instance()->get("vpath"),1), '/'))));
		base::instance()->set("directory", dirname(base::instance()->get("vpath")));
		if ( base::instance()->get("settings.plugins_dir") ) {
			base::instance()->set('AUTOLOAD', base::instance()->get("ROOT").base::instance()->get("settings.plugins_dir"));
		}		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
			//base::instance()->set("HOOKS", new Hooks());
			
			if ( !Base::instance()->get("HOOKS") ) {
				Base::instance()->set("HOOKS", new Hooks());
				echo "Instansiated.";
				exit;
			}
			
		}
		if ( $config !== NULL && file_exists($config) ) {
			base::instance()->config($config);
		}
		self::$instance->connect();
		base::instance()->get("HOOKS")->do_action('CMS');
		return self::$instance;
	}
	
	public function connect() {
		try {
			$this->dbh = new DB\SQL(
				Base::instance()->get('WebsiteDB.dsn'), Base::instance()->get('WebsiteDB.user'), Base::instance()->get('WebsiteDB.pass')
				, array( PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_OBJ)
			);
			Base::instance()->set("dbh", $this->dbh);
		} catch (PDOException $e) {
			die('Connection failed: ' . $e->getMessage());
		}
	}

	public function run() {
		Base::instance()->set('ONERROR', function(){ return false; });
		Base::instance()->set('DEBUG', true);
		Base::instance()->set('CACHE', false);
		Base::instance()->set("QUIET", false);
		
		/* Change these based on config */
		Base::instance()->get("HOOKS")->do_action('run');
		Base::instance()->get("HOOKS")->do_action('pre_config');

		$this->connect();
		Base::instance()->get("HOOKS")->do_action('db_connect');

		if ( Base::instance()->get("PATH") == Base::instance()->get("vpath") && stripos(strrev(Base::instance()->get("PATH")), 'lmth.xedni') === 0 ) {
			header("Location: ".str_replace("index.html", "", Base::instance()->get("vpath")));
		}
		
		$this->detect_device();

		Base::instance()->get("HOOKS")->do_action("custom");

		$this->get_content();
				
		$this->security = ( isset($this->content->security) )  ? json_decode($this->content->security)  : array();
		$this->meta     = ( isset($this->content->meta_data) ) ? json_decode($this->content->meta_data) : array();
		$this->theme    = ( isset($this->content->theme) )     ? json_decode($this->content->theme)     : array();

		$this->template = ( !isset($this->theme->template) ) ? "default.html" : $this->theme->template;
		$this->prepared = (object) array("status"=>200, "page_title"=>"Error 404 - Page Not Found");
		
		if ( isset($this->content->page_title) ) {
			$this->prepared->page_title = $this->content->page_title;
		}
		
		Base::instance()->get("HOOKS")->do_action('security');
		
		if (  !empty($this->content->internal_path) ) {
			if (file_exists(Base::instance()->get('ROOT').$this->content->internal_path)) {
				$this->prepared->script = Base::instance()->get('ROOT').$this->content->internal_path;
			}
		} elseif ( isset($this->content->virtual_path) ) {
			if ( isset($this->content->virtual_path) ) {
				if ( isset($this->content->content) ) {
					$this->prepared->content = stripcslashes(htmlspecialchars_decode($this->content->content));
				} else {
					if ( substr($this->content->virtual_path, -1) == '/' && Base::instance()->get("vpath") != "/" ) {
						if (file_exists(Base::instance()->get('ROOT').$this->content->virtual_path.'index.php')) {
							$this->prepared->script = Base::instance()->get('ROOT').$this->content->virtual_path.'index.php';
						}
					}
				}
			} else {
				if ( isset($this->content->content) && strlen($this->content->content) > 0 ) {
					$this->prepared->content = $this->content->content;
					echo "asdf";
				} else {
					echo "Error: No script available to handle this page.";
				}
			}
		}

		if ( isset($this->prepared->internal) ) {
			$this->prepared->response = $this->prepared->internal;
		} elseif ( isset($this->prepared->content) || isset($this->prepared->script) ) {
			if ( isset($this->prepared->script) && file_exists($this->prepared->script)) {
				ob_start();
				include($this->prepared->script);
				$this->prepared->content = ob_get_clean();
			}
		} else {
			$this->prepared->status = 404;
			if ( !isset($this->permissions->cms) ) {
				$this->prepared->response  = "<h4>404 Not Found</h4>\n";
				$this->prepared->response .= "<p>The requested resource could not be found but may be available again in the future.</p>";
			} else {
				$this->prepared->response  = "<h4>Ready for content</h4>\n";
				$this->prepared->response .= "<p>Until static or dynamic content is assigned to this VirtualPath or SiteLevel, the public will see a general Error 404 response.</p>";
			}
		}
		
		$this->inner_content = ( $this->prepared->status == 200 ) ? $this->prepared->content : $this->prepared->response;
		
		if ( in_array(Base::instance()->get("vpath"), array_keys(Base::instance()->get("ROUTES")) )) {
			Base::instance()->set("QUIET", false);
			ob_start();
			Base::instance()->run();
			ob_get_clean();
			
			if ( Base::instance()->get("RESPONSE") ) {
				$this->prepared->status = 200;
				$this->inner_content = Base::instance()->get("RESPONSE");
			}
			if ( Base::instance()->get("page_title") ) {
				$this->prepared->page_title = Base::instance()->get("page_title");
			}	
		}

		if (  !empty(Base::instance()->get("page_title")) ) {
			 $this->prepared->page_title =Base::instance()->get("page_title");
		}
		$this->select_template();
		$this->process_template();
	}
	
	public function set_content($content) {
		Base::instance()->set("content", $content);
	}
	
	public function db() {
		return $this->dbh;
	}	

	private function detect_device() {
		$detect = new Mobile_Detect;
		Base::instance()->set("deviceType", ( $detect->isMobile() ? ( $detect->isTablet() ? 'tablet' : 'phone' ) : 'desktop' ));
	}
	
	private function get_content() {
		$vpathParts = explode("/", Base::instance()->get("vpath"));
		$count = count($vpathParts);
		$path = Base::instance()->get("vpath");
		$query = $this->dbh->prepare('SELECT * FROM content WHERE virtual_path = "'.Base::instance()->get("vpath").'" LIMIT 1');
		$query->execute();
		$sql = "";
		if ( $query->rowCount() == 0 ) {
			for ($i = $count; $i >= 1; $i--) {
				if ( dirname(Base::instance()->get("vpath")) == "/" ) { continue; }
				if ( dirname($path) != "/" ) {
					$sql = 'WHERE virtual_path = "'.dirname($path).'/ " ';
				}
				$path = dirname($path);
				$query = $this->dbh->prepare('SELECT * FROM content '.$sql.' LIMIT 1');
				$query->execute();		
				if ( $query->rowCount() == 1 ) {
					$this->content = $query->fetchAll()[0];
					break;
				}
			}
		} else {
			$this->content = $query->fetchAll()[0];
		}
	}
	
	public function isContentProtected() {
		if ( $this->content->protected == "Y" ) {
			return true;
		}
		return false;
	}

	public function inMaintenanceMode() {
		$triggerFile = Base::instance()->get('ROOT') . "/maintenance.txt";
		if ( file_exists($triggerFile) ) {
			return file_get_contents($triggerFile);
		}
		return false;
	}	
	
	public function select_template() {
		$ext = pathinfo(basename($this->template), PATHINFO_EXTENSION);
		$possibilities = array_unique(array(
			  Base::instance()->get('ROOT') . "/Templates/" . basename($this->template, '.'.$ext) . '/' . Base::instance()->get("deviceType") . '.' . $ext
			, Base::instance()->get('ROOT') . "/Templates/" . basename($this->template, '.'.$ext) . '.' . Base::instance()->get("deviceType") . '.' . $ext
			, Base::instance()->get('ROOT') . "/Templates/" . basename($this->template, '.'.$ext) . '.' . $ext
			, Base::instance()->get('ROOT') . "/Templates/default/".Base::instance()->get("deviceType").".html"
			, Base::instance()->get('ROOT') . "/Templates/default.".Base::instance()->get("deviceType").".html"
			, Base::instance()->get('ROOT') . "/Templates/default.html"
		));

		$nothing_so_far = true;
		foreach($possibilities as $check) {
			if ( file_exists($check) ) {
				$this->template = Template::instance()->resolve(file_get_contents($check));
				unset($nothing_so_far);
				break;
			}
		}
		
		if ( isset($nothing_so_far) ) {
			$possibilities = str_replace(Base::instance()->get('ROOT'), __DIR__, $possibilities);
			foreach($possibilities as $check) {
				if ( file_exists($check) ) {
					$this->template = Template::instance()->resolve(file_get_contents($check));
					unset($nothing_so_far);
					break;
				}
			}
		}

		if ( isset($nothing_so_far) ) {
			 die("No templates found in /Templates/ or ".str_replace($f3->get('ROOT'), '', __DIR__)."/Templates/.");
		}
		
	}
	
	public function process_template() {
		
		$html = new PureHTML();
		$html->scan($this->template, "head");
		$this->template = $html->scrub($this->template);
		
		// Dynamics are standalone PHP files that do not require the use of the F3 system
		if ( strlen($this->inner_content) > 0 ) {
			$domOfDynamic = new DOMDocument();
			$domOfDynamic->loadHTML($this->inner_content);
			$frag = $domOfDynamic->saveHTML();
			$html->scan($frag);
			$scrubbed_dynamic = $html->scrub($frag);
			$this->template = $html->splice($this->template, $scrubbed_dynamic, "content");
		}

		$dynamics_depth = 5;
		$already_processed = array();
		$already_processed[] = "content";
		for($i=0; $i<=($dynamics_depth-1); $i++) {
			libxml_use_internal_errors(true);
			$tmpDOM = new DOMDocument();
			$tmpDOM->loadHTML(html_entity_decode($this->template, ENT_HTML5));
			foreach($tmpDOM->getElementsByTagName('*') as $tag) {
				if ( !in_array($tag->getAttribute("id"), $already_processed) ) {
					$already_processed[] = $tag->getAttribute("id");
					$typeOfDynamic = ( file_exists($_SERVER['DOCUMENT_ROOT']."/dynamics/".$tag->getAttribute("id").".php" ) )
						? ( ( file_exists($_SERVER['DOCUMENT_ROOT']."/dynamics/".$tag->getAttribute("id").".html" ) )
						? "html" : "php") : false;
					if ( $typeOfDynamic !== false && ( $typeOfDynamic == "html" || $typeOfDynamic == "php" ) ) {
						ob_start();
						include($_SERVER['DOCUMENT_ROOT']."/dynamics/".$tag->getAttribute("id").".php");
						$dynamic_output = ob_get_clean();
						if ( $typeOfDynamic == "html" ) {						
							$unscrubbed_dynamic_content = trim((Template::instance()->render('/dynamics/'.$tag->getAttribute("id").'.html')));
							if ( strlen($unscrubbed_dynamic_content) > 0 ) {
								$html->scan($unscrubbed_dynamic_content);
								$scrubbed_dynamic = $html->scrub($unscrubbed_dynamic_content);
								$this->template = $html->splice($this->template, $scrubbed_dynamic, $tag->getAttribute("id"));
							}
						}
						if ( $typeOfDynamic !== false && $typeOfDynamic == "php" ) {
							$domOfDynamic = new DOMDocument();
							if ( strlen($dynamic_output) > 0 ) {
								$domOfDynamic->loadHTML($dynamic_output);
								$frag = $domOfDynamic->saveHTML();
								$html->scan($frag);
								$scrubbed_dynamic = $html->scrub($frag);
								$this->template = $html->splice($this->template, $scrubbed_dynamic, $tag->getAttribute("id"));
							}
						}
					}
				}
			}	
		}
		
		base::instance()->get("HOOKS")->do_action('end_of_dom', $html);
		$html->scan($this->template, "body");
		$doc = $html->rebuild($this->template);
		$html->title($doc, ( isset($this->prepared->page_title) ? html_entity_decode($this->prepared->page_title) : html_entity_decode($this->content->page_title)));
		Base::instance()->get("HOOKS")->do_action('page_title');
		$this->html = $html->beautifyDOM($doc);
	}

	public function __destruct() {
		echo $this->html;
	}
	
}

?>