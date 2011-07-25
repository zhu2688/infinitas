<?php
	App::import('lib', 'Libs.InfinitasAppShell');
	
	class InstallerTask extends InfinitasAppShell {
		public $tasks = array('Infinitas', 'Installer', 'InfinitasPlugin');

		public $config = array(
			'engine' => '',
			'connection' => array(
				'host' => 'localhost',
				'login' => 'infinitas',
				'password' => 'infinitas',
				'database' => 'infinitas',
				'port' => 3306,
				'prefix' => ''
			),
			'root' => array(
				'login' => 'root',
				'password' => 'root',
			)
		);

		public function  __construct(&$dispatch) {
			parent::__construct($dispatch);

			$this->InstallerLib = new InstallerLib();
		}

		/**
		 * display the licence. If you need it confirmed it will display and require
		 * the user to accept. if they do not it will exit.
		 *
		 * @param bool $confirm should the user confirm
		 * @return mixed bool true, accepted
		 */
		public function welcome($confirm = true){
			$this->Infinitas->h1('Welcome to Infinitas');
			$this->Infinitas->out($this->InstallerLib->getWelcome('text'));
			$this->Infinitas->h2('MIT Licence');
			$this->Infinitas->out($this->InstallerLib->getLicense('text'));
			
			if($confirm){
				$this->Infinitas->li(
					array(
						'[Y]es',
						'[N]o',
						'[Q]uit'
					)
				);
				$this->Infinitas->br();
				$input = strtoupper($this->in('Do you accept the MIT license?', array('Y', 'N', 'Q')));

				switch ($input) {
					case 'Y':
						return true;
						break;

					default:
						$this->Infinitas->quit();
						break;
				}
			}
			
		}

		/**
		 * collect database related configurations and validate them so the installer
		 * can later
		 */
		public function database($validationFailed = false){
			$this->_getDbEngine($validationFailed);
			$this->_getDbConnection($validationFailed);
			$this->_validateDbConnection();
		}

		public function install(){
			$this->Infinitas->h1(__('Installing', true));
			foreach($this->config['connection'] as $k => $v){
				echo $k . ' :: ' . $v . "\r\n";
			}
			Configure::write('default', 2);
			$this->_getSampleDataOption();


			App::import('Core', 'ConnectionManager');

			$dbConfig = $this->InstallerLib->cleanConnectionDetails($this->config);
			$this->InstallerLib->config = $this->config;

			$db = ConnectionManager::create('default', $dbConfig);

			$plugins = App::objects('plugin');
			natsort($plugins);

			App::import('Lib', 'Installer.ReleaseVersion');
			$Version = new ReleaseVersion();

			//Install app tables first
			$this->interactive('Installing: App data');
			$result = $this->InstallerLib->installPlugin($Version, $dbConfig, 'app');

			$result = true;
			if($result) {
				$this->interactive('Installing: Installer');
				$result = $result && $this->InstallerLib->installPlugin($Version, $dbConfig, 'Installer');
			}

			if($result) {
				//Then install all other plugins
				foreach($plugins as $plugin) {
					if($plugin == 'Installer') {
						continue;
					}

					$this->interactive(sprintf('Installing: %s', $plugin));
					$result = $result && $this->InstallerLib->installPlugin($Version, $dbConfig, $plugin);
					var_dump($result);
				}
				
				$this->interactiveClear();
			}

			$this->Plugin = ClassRegistry::init('Installer.Plugin');
			foreach($plugins as $pluginName) {
				$this->interactive(sprintf('Updating: %s', $plugin));
				$this->Plugin->installPlugin($pluginName, array('sampleData' => false, 'installRelease' => false));
			}

			$this->interactiveClear();

			return $result;
		}

		public function installLocalPlugin(){
			$plugins = $this->__getPluginToInstall();
			if(!$plugins){
				return false;
			}

			if(!is_array($plugins)){
				$plugins = array($plugins);
			}

			$Plugin = ClassRegistry::init('Installer.Plugin');

			foreach($plugins as $plugin){
				$output = sprintf('Update for %s has failed :(', $plugin);
				if($Plugin->installPlugin($plugin, array('sampleData' => false, 'installRelease' => false))){
					$output = sprintf('%s Plugin updated', $plugin);
				}

				$this->Infinitas->out($output);
			}

			$this->Infinitas->pause();
		}

		public function updatePlugin(){
			$plugins = $this->__getPluginToUpdate();
			if(!$plugins){
				return false;
			}

			if(!is_array($plugins)){
				$plugins = array($plugins);
			}

			$Plugin = ClassRegistry::init('Installer.Plugin');

			foreach($plugins as $plugin){
				$output = sprintf('Update for %s has failed :(', $plugin);
				if($Plugin->installPlugin($plugin)){
					$output = sprintf('%s Plugin updated', $plugin);
				}

				$this->Infinitas->out($output);
			}

			$this->Infinitas->pause();
			$this->updatePlugin();
		}


		/**
		 * get the users database engine preference
		 */
		public function _getDbEngine($validationFailed){
			$this->Infinitas->h1(__('Database configuration', true));

			if($validationFailed){
				$this->Infinitas->p(__('The connection test failed to connect to '.
				'your database engine, please ensure the details provided are '.
				'correct', true));
			}
			
			$dbs = $this->InstallerLib->getSupportedDbs();

			$this->Infinitas->br();
			$this->config['connection']['driver'] = strtolower(
				$this->in(
					'Which database engine should be used?',
					array_keys($dbs),
					current(array_keys($dbs))
				)
			);
			return;
		}

		/**
		 * get the connection details for the selected database engine
		 */
		public function _getDbConnection($validationFailed){
			$this->Infinitas->h1(sprintf('%s (%s)', __('Database configuration', true), $this->config['connection']['driver']));

			if($validationFailed){
				$this->Infinitas->p(__('The connection test failed to connect to '.
				'your database engine, please ensure the details provided are '.
				'correct', true));
			}
			
			$this->config['connection']['host']     = $this->in('HostName', null, $this->config['connection']['host']);
			$this->config['connection']['login']    = $this->in('Username', null, $this->config['connection']['login']);
			$this->config['connection']['password'] = $this->in('Password', null, $this->config['connection']['password']);
			$this->config['connection']['database'] = $this->in('Database', null, $this->config['connection']['database']);
			$this->config['connection']['prefix']   = $this->in('Prefix', null, $this->config['connection']['prefix']);

			$this->Infinitas->br();
			$this->Infinitas->out('Would you like to use a root pw for the installer');
			$this->Infinitas->out('Root logins will not be saved');
			$this->Infinitas->out('[Y]es, [N]o or [B]ack');
			$input = strtoupper($this->in('Use Root password', array('Y', 'N', 'B'), 'N'));

			$databaseEngine = null;
			switch ($input) {
				case 'Y':
					$this->config['root']['login']    = $this->in('Root Username', null, $this->config['root']['login']);
					$this->config['root']['password'] = $this->in('Root Password', null, $this->config['root']['password']);
					break;

				case 'Q':
					$this->welcome();
					break;

				default:
					// reset defaults
					$this->config['root'] = array('username' => '', 'password' => '');
					break;
			}

			return true;
		}

		/**
		 * check that the details for the database given are correct.
		 */
		public function _validateDbConnection(){
			$this->Infinitas->h1(sprintf(__('Testing %s connection', true), $this->config['connection']['driver']));
			if(!$this->InstallerLib->testConnection($this->config['connection'])){
				$this->database(false);
			}
		}

		public function _getSampleDataOption(){
			$this->Infinitas->out('Would you like to install sample data');
			$this->Infinitas->out('[Y]es, [N]o or [B]ack');
			$input = strtoupper($this->in('Sample Data', array('Y', 'N', 'B'), 'N'));

			$this->config['sample_data'] = false;
			switch ($input) {
				case 'Y':
					$this->config['sample_data'] = true;
					break;

				case 'B':
					$this->welcome();
					break;
			}
		}

		private function __getPluginToUpdate(){
			$Plugin = ClassRegistry::init('Installer.Plugin');
			Configure::write('debug', 2);
			$plugins = array();
			foreach($Plugin->getInstalledPlugins() as $plugin){
				$status = $Plugin->getMigrationStatus($plugin);
				
				if($status['migrations_behind']){
					$plugins[] = $plugin;
				}
			}

			do {
				$this->Infinitas->h1('Interactive Install Shell');
				foreach($plugins as $i => $plugin){
					$this->Infinitas->out($i + 1 . ') ' . $plugin);
				}
				$this->Infinitas->out('A)ll');

				$this->Infinitas->br();
				$input = strtoupper($this->in('Which plugin do you want to update?'));

				if(isset($plugins[$input - 1])){
					return $plugins[$input - 1];
				}

				if($input == 'A'){
					return $plugins;
				}
			} while($input != 'Q');
		}

		private function __getPluginToInstall(){
			$plugins = ClassRegistry::init('Installer.Plugin')->getNonInstalledPlugins();
			sort($plugins);

			do {
				$this->Infinitas->h1('Interactive Install Shell');
				foreach($plugins as $i => $plugin){
					$this->Infinitas->out($i + 1 . ') ' . $plugin);
				}
				$this->Infinitas->out('A)ll');

				$this->Infinitas->br();
				$input = strtoupper($this->in('Which plugin do you want to install?'));

				if(isset($plugins[$input - 1])){
					return $plugins[$input - 1];
				}

				if($input == 'A'){
					return $plugins;
				}
			} while($input != 'Q');
		}
	}