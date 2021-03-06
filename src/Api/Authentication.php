<?php
	namespace Bolt\Api;

	use Bolt\Base;

	class Authentication extends Base
	{
		public $schemas;

		public $scheme;
		public $parameters;

		public function __construct()
		{
			$this->schemas = new \stdClass();
		}

		public function add($schema, $class)
		{
			$this->schemas->{$schema} = $class;
		}

		public function parse($header)
		{
			list($scheme, $data) = explode(" ", $header, 2);

			$parameters = explode(",", $data);
			$results = array();

			$this->scheme($scheme);

			foreach ($parameters as $parameter)
			{
				$tmp = strpos($parameter, "=");

				if ($tmp === false || ($tmp == (strlen($parameter) - 1) || $tmp == (strlen($parameter) - 2)) && $parameter[strlen($parameter) - 1] == "=")
				{
					$results[] = $parameter;
				}
				else
				{
					list($key, $value) = explode("=", $parameter, 2);

					$key = trim($key);
					$value = trim($value, '"');

					$results[$key] = $value;
				}
			}

			if (count($results) == 1)
			{
				if ($results[0] == $data)
				{
					$results = array("token" => $data);
				}
			}

			$this->parameters((object)$results);
		}

		public function token()
		{
			return $this->parameters->token;
		}
	}
?>
