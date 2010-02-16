<?php

/**
 * A request sent to Amazon's S3 service
 */
class Facade_S3_Request implements Facade_Request
{
	private $_headers;
	private $_stream;
	private $_accesskey;
	private $_method;
	private $_secret;
	private $_socket;
	private $_path;

	/**
	 * Constructor
	 */
	public function __construct($socket, $accesskey, $secret, $method, $path)
	{
		$this->_headers = new Facade_HeaderCollection();
		$this->_accesskey = $accesskey;
		$this->_secret = $secret;
		$this->_socket = $socket;
		$this->_method = $method;
		$this->_path = $path;
	}

	/**
	 *
	 */
	public function setContentType($mimetype)
	{
		return $this->setHeader('Content-Type: '.$mimetype);
	}

	/**
	 *
	 */
	public function setAcl($acl)
	{
		return $this->setHeader('x-amz-acl: '.$acl);
	}

	/**
	 *
	 */
	public function setDate($timestamp)
	{
		return $this->setHeader('Date: '. gmdate('D, d M Y H:i:s T', $timestamp));
	}

	/* (non-phpdoc)
	 * @see Facade_Request::setStream()
	 */
	public function setStream($stream)
	{
		$this->_stream = $stream;
		return $this;
	}

	/* (non-phpdoc)
	 * @see Facade_Request::setStream()
	 */
	public function setHeader($header)
	{
		$this->_headers->add($header);
		return $this;
	}

	/* (non-phpdoc)
	 * @see Facade_Request::setStream()
	 */
	public function getHeaders()
	{
		return $this->_headers;
	}

	/**
	 * Sends the request
	 */
	public function send()
	{
		$headers = $this->getHeaders();

		// set some defaults
		if(!$headers->contains('Date')) $this->setDate(time());
		if(!$headers->contains('Host')) $this->setHeader('Host: '.Facade_S3::S3_HOST);
		if(!$headers->contains('x-amz-acl')) $this->setHeader('x-amz-acl: private');

		// if there is a stream, add a content length
		if(isset($this->_stream))
		{
			$this->setHeader('Content-Length: '.$this->_stream->getLength());
		}

		// add the amazon signature
		$headers->set(sprintf('Authorization: AWS %s:%s',
			$this->_accesskey, $this->signature()));

		// write the pre-amble
		$this->_socket->writeRequest(
			$this->_method,
			$this->_path,
			$this->getHeaders()
			);

		// most requests have a content stream
		if($headers->contains('Content-Length') && $headers->value('Content-Length'))
		{
			$this->_socket->copy($this->_stream);
		}

		// build a response
		return new Facade_S3_Response($this->_socket);
	}

	// ---------------------------------------------------------
	// signature helper methods

	private function signature()
	{
		$headers = $this->getHeaders();
		$date = $headers->value('Date');
		$md5 = $headers->value('Content-MD5');
		$type = $headers->value('Content-Type');

		// canonicalize the amazon headers
		$amazonHeaders = $headers->filter('/^x-amz/i')->sort();
		$canonicalized = '';

		foreach ($amazonHeaders as $header)
			$canonicalized .= strtolower($header->getName()).':'.$header->getValue()."\n";

		// build the string to sign
		$plaintext = sprintf("%s\n%s\n%s\n%s\n%s%s",
			$this->_method,
			$md5,
			$type,
			$date,
			$canonicalized,
			$this->_path
			);

		return $this->base64($this->hmacsha1(	$this->_secret, $plaintext));
	}

	/**
	 * @see http://pear.php.net/package/Crypt_HMAC/
	 */
	private function hmacsha1($key, $data)
	{
		if (strlen($key) > 64)
			$key = pack("H40", sha1($key));
		if (strlen($key) < 64)
			$key = str_pad($key, 64, chr(0));
		$ipad = (substr($key, 0, 64) ^ str_repeat(chr(0x36), 64));
		$opad = (substr($key, 0, 64) ^ str_repeat(chr(0x5C), 64));
		return sha1($opad . pack("H40", sha1($ipad . $data)));
	}

	/**
	 * Returns the base64 version of a string
	 */
	private function base64($str)
	{
		$ret = "";
		for($i = 0; $i < strlen($str); $i += 2)
			$ret .= chr(hexdec(substr($str, $i, 2)));
		return base64_encode($ret);
	}
}
