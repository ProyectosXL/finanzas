<?php


class DotEnv
{
    /**
     * The directory where the .env file can be located.
     *
     * @var string
     */
    protected $path;
    protected $values = [];


    public function __construct(string $path)
    {   
         
        if(!file_exists($path)) {
             throw new \InvalidArgumentException(sprintf('%s does not exist', $path));
        }
        $this->path = $path;
    }

    public function load() :void
    {
        if (!is_readable($this->path)) {
             throw new \RuntimeException(sprintf('%s file is not readable', $this->path));
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {

            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            $this->values[$name] = $value;

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    public function listVars(){
        $this->load();

        $getVal = function($key) {
            if (isset($this->values[$key])) {
                return $this->values[$key];
            }
            $val = getenv($key);
            if ($val !== false) {
                return $val;
            }
            if (isset($_ENV[$key])) {
                return $_ENV[$key];
            }
            if (isset($_SERVER[$key])) {
                return $_SERVER[$key];
            }
            return '';
        };

        $vars = array(

            'HOST_CENTRAL' => $getVal('HOST_CENTRAL'),
            'HOST_LOCALES' => $getVal('HOST_LOCALES'),
            'HOST_APPS' => $getVal('HOST_APPS'),
            'DATABASE_CENTRAL' => $getVal('DATABASE_CENTRAL'),
            'DATABASE_LOCALES' => $getVal('DATABASE_LOCALES'),
            'DATABASE_TANGOBIS' => $getVal('DATABASE_TANGOBIS'),
            'DATABASE_UY' => $getVal('DATABASE_UY'),
            'DATABASE_SUC_UY' => $getVal('DATABASE_SUC_UY'),
            'DATABASE_APPS' => $getVal('DATABASE_APPS'),
            'USER' => $getVal('USER'),
            'PASS' => $getVal('PASS'),
            'PASS_LOCALES' => $getVal('PASS_LOCALES'),
            'CHARACTER' => $getVal('CHARACTER'),

            'ENV' => $getVal('ENV'),

        );

        return $vars;


    }

}