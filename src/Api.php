<?php
	namespace Bolt;

	use DirectoryIterator;

	class Api extends Base
	{
		public $request;
		public $response;

		public $authentication;
		public $connections;
		public $route;

		private $permissions;
		private $whitelist;

		public function __construct($connections = null)
		{
			$this->response = new Api\Response();
			$this->request = new Api\Request();

			$this->authentication = new Api\Authentication();
			$this->connections = new Api\Connections($connections);
			$this->route = new Api\Route(true);

			if ($this->request->format != "json")
			{
				$this->response->setView($this->request->format);
			}
		}

		public function activate()
		{
			$this->routing();

			if ($this->route->info->verb == "OPTIONS")
			{
				$this->handleOptions($this->route->controller);
			}

			$this->loadWhitelist();

			if ($this->checkWhitelist() === false)
			{
				try
				{
					$this->authenticate();
				}
				catch (Exceptions\Authentication $exception)
				{
					$this->response->status($exception->getCode());
				}

				// allow aliasing logged in user id as 'me'
				if ($this->route->info->id == "me")
				{
					global $_ID;
					$this->route->info->id = $_ID;
				}

				if ($this->authentication->scheme() == "Global")
				{
					$this->enforcePermission($this->route->controller, $this->route->method);
				}
			}
		}

		public function enforcePermission($controller, $name)
		{
			$result = $this->checkPermission($controller, $name);

			if ($result === false)
			{
				$this->response->status(403);
			}

			return true;
		}

		public function checkPermission($controller, $name)
		{
			$result = false;

			$permissions = $this->permissions->system->$controller;
			$requiredPermission = constant("\\Controllers\\Permissions\\" . $controller . "::" . strtoupper($name));

			if (($permissions & $requiredPermission) === $requiredPermission)
			{
				$result = true;
			}

			return $result;
		}

		private function checkWhitelist()
		{
			if ($this->route->controller == "")
			{
				return true;
			}

			for ($loop = 0; $loop < count($this->whitelist); $loop++)
			{
				$rule = $this->whitelist[$loop];

				if ($rule->controller == $this->route->controller)
				{
					if (!isset($rule->methods))
					{
						return true;
					}

					foreach ($rule->methods as $method)
					{
						if ($method == $this->route->method)
						{
							return true;
						}
					}
				}
			}

			return false;
		}

		private function loadWhitelist()
		{
			$this->whitelist = $this->loadJsonConfig(ROOT_SERVER . "/library/whitelist.json");
		}

		private function loadJsonConfig($filename)
		{
			$fileHandler = new Files();
			return json_decode($fileHandler->load($filename));
		}

		public function fetchAvailableOptions()
		{
			$possibleMethods = array("GET", "POST", "PUT", "DELETE", "HEAD", "PATCH");
			$available = array();

			$methodTail = str_replace(strtolower($this->route->info->verb), "", $this->route->method);

			foreach ($possibleMethods as $next)
			{
				if (method_exists("\\App\\Controllers\\" . $this->route->controller, strtolower($next) . $methodTail) === true)
				{
					$available[] = $next;
				}
			}

			return $available;
		}

		public function handleOptions()
		{
			$available = $this->fetchAvailableOptions();
			$headers[] = "Allow: " . implode(",", $available);
			$headers[] = "Access-Control-Allow-Methods: " . implode(",", $available);
			$this->response->status(204, null, $headers);
		}

		public function controllers()
		{
			$results = array();

			foreach (new DirectoryIterator(ROOT_SERVER . "app/Controllers/") as $fileInfo)
			{
				if ($fileInfo->isDot() || $fileInfo->isDir() || $fileInfo->getExtension() != "php")
				{
					continue;
				}

				$results[] = $fileInfo->getBasename("." . $fileInfo->getExtension());
			}

			return $results;
		}

		public function routing()
		{
			if ($this->route->controller != "" && $_SERVER['REQUEST_METHOD'] != "OPTIONS")
			{
				$controller = "App\\Controllers\\" . $this->route->controller;

				if (!class_exists($controller))
				{
					$this->response->status(404);
				}
				elseif (!method_exists($controller, $this->route->method))
				{
					$available = $this->fetchAvailableOptions();

					if ($available != array())
					{
						$available = $this->fetchAvailableOptions();
						$headers[] = "Allow: " . implode(",", $available);
						$headers[] = "Access-Control-Allow-Methods: " . implode(",", $available);
						$this->response->status(405, false, $headers);
					}
					else
					{
						$this->response->status(404);
					}
				}
			}
		}

		public function authenticate()
		{
			if (!isset($this->request->headers->authorization))
			{
				$this->response->status(401);
			}

			$this->authentication->parse($this->request->headers->authorization());

			$available = $this->authentication->schemas();

			$authClass = $available->{$this->authentication->scheme()};

			if (!class_exists($authClass))
			{
				$this->response->status(400, "Unknown authentication schema `" . $this->authentication->scheme() . "`");
			}

			$authHandler = new $authClass($this->connections);

			return $authHandler->authenticate($this->authentication->parameters());
		}
	}
?>
