<?php

namespace RubtsovAV\YandexWordstatParser\Browser;

use RubtsovAV\YandexWordstatParser\Query;
use RubtsovAV\YandexWordstatParser\Result;
use RubtsovAV\YandexWordstatParser\YandexUser;
use RubtsovAV\YandexWordstatParser\Captcha\Image as CaptchaImage;
use RubtsovAV\YandexWordstatParser\Browser\ReactPhantomJs\Process as PhantomJsProcess;
use RubtsovAV\YandexWordstatParser\Browser\ReactPhantomJs\Message as PhantomJsMessage;
use RubtsovAV\YandexWordstatParser\Exception\WrongResponseException;
use RubtsovAV\YandexWordstatParser\Exception\BrowserException;
use React\EventLoop\Factory as EventLoopFactory;

class ReactPhantomJs extends AbstractBrowser
{
	/**
	 * The path to executable file of phantomjs
	 * 
	 * @var string
	 */
	protected $phantomJsPath = 'phantomjs';

	/**
	 * The browser window width
	 * 
	 * @var integer
	 */
	protected $viewportWidth = 1920;

	/**
	 * The browser window height
	 * 
	 * @var integer
	 */
	protected $viewportHeight = 974;

	/**
	 * Running the phantomjs process
	 * 
	 * @var null|\RubtsovAV\YandexWordstatParser\Browser\ReactPhantomJs\Process
	 */
	protected $phantomjs;

	/**
	 * Result of the sent query
	 * 
	 * @var null|\RubtsovAV\YandexWordstatParser\Result
	 */
	protected $result;

	/**
	 * Set the path to executable file of phantomjs
	 * 
	 * @param string $path
	 */
	public function setPhantomJsPath(string $path)
	{
		$this->phantomJsPath = $path;
	}

	/**
	 * Returns the path to executable file of phantomjs
	 * 
	 * @return string
	 */
	public function getPhantomJsPath()
	{
		return $this->phantomJsPath;
	}

	/**
	 * Set the browser window sizes
	 * 
	 * @param int $width 
	 * @param int $height
	 */
	public function setViewportSize(int $width, int $height)
	{
		$this->viewportWidth  = (int) $width;
        $this->viewportHeight = (int) $height;
	}

	/**
	 * Returns the browser window width
	 * 
	 * @return int
	 */
	public function getViewportWidth()
	{
		return $this->viewportWidth;
	}

	/**
	 * Returns the browser window height
	 * 
	 * @return int
	 */
	public function getViewportHeight()
	{
		return $this->viewportHeight;
	}

	/**
	 * Send query by the user yandex
	 * 
	 * @param  \RubtsovAV\YandexWordstatParser\Query 	  $query
	 * @param  \RubtsovAV\YandexWordstatParser\YandexUser $yandexUser
	 * 
	 * @return \RubtsovAV\YandexWordstatParser\Result                      
	 */
	public function send(Query $query, YandexUser $yandexUser)
	{
		$loop = EventLoopFactory::create();
		$this->result = null;
		
		$options = [];
		if ($yandexUser->getStoragePath()) {
			$options['--cookies-file'] = $yandexUser->getStoragePath() . '/cookies.txt';
		}
		$phantomjs = $this->phantomjs = $this->createPhantomJsProcess($options);
		$phantomjs->start($loop);

		$message = new PhantomJsMessage('setViewportSize', [
			'width' => $this->getViewportWidth(),
			'height' => $this->getViewportHeight(),
		]);
		$phantomjs->sendMessage($message);

		$message = new PhantomJsMessage('setHeaders', [
			'User-Agent' => $this->getUserAgent(),
			'Accept-Language' => $this->getAcceptLanguage(),
		]);
		$phantomjs->sendMessage($message);

		$message = new PhantomJsMessage('setYandexUser', $yandexUser->toArray());
		$phantomjs->sendMessage($message);

		$message = new PhantomJsMessage('setTimeout', $this->getTimeout());
		$phantomjs->sendMessage($message);

		if ($proxy = $this->getProxy()) {
			$message = new PhantomJsMessage('setProxy', $proxy->toArray());
			$phantomjs->sendMessage($message);
		}

		$message = new PhantomJsMessage('query', $query->toArray());
		$phantomjs->sendMessage($message);

	    $phantomjs->on('message', function(PhantomJsMessage $message) {
	    	$this->onMessage($message);
	    });

	    $phantomjs->on('error', function($error) {
	        throw new BrowserException($error);
	    });

	    $phantomjs->on('exit', function ($code) use ($loop) {
		    $loop->stop();
		});

	    $loop->run();

	    return $this->result;
	}

	protected function createPhantomJsProcess(array $options = [])
	{
		return new PhantomJsProcess($this->getPhantomJsPath(), $options);
	}

	protected function onMessage(PhantomJsMessage $message) 
	{
        switch ($message->getType()) {
        	case 'captcha':
        		$captcha = new CaptchaImage($message->getContent());
        		if (!$this->solveCaptcha($captcha)) {
        			throw new BrowserException('solve captcha failed');
        		}
        		$message = new PhantomJsMessage('captchaAnswer', $captcha->getAnswer());
				$this->phantomjs->sendMessage($message);
        		break;

        	case 'result':
        		$this->result = $this->createResultFromMessage($message);
        		$this->phantomjs->stop();
        		break;
        }
    }

	protected function createResultFromMessage(PhantomJsMessage $message)
	{
		$data = $message->getContent();
	    return Result::fromArray($data);
	}	
}