<?php
/**
 * Akeeba Release Maker
 * An automated script to upload and release a new version of an Akeeba component.
 *
 * @package    AkeebaReleaseMaker
 * @copyright  Copyright (c)2006-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    GNU/GPLv3
 */
class ArmSftp
{
	private $ssh = null;
	private $fp  = null;

	private $config = null;

	public function __construct($config)
	{
		$this->config = $config;

		if (!function_exists('ssh2_connect'))
		{
			throw new Exception('You do not have the SSH2 PHP extension, therefore could not connect to SFTP server.');
		}

		$this->ssh = ssh2_connect($config->hostname, $config->port);

		if (!$this->ssh)
		{
			throw new Exception('Could not connect to SFTP server: invalid hostname or port');
		}

		if ($config->pubkeyfile && $config->privkeyfile)
		{
			if (!@ssh2_auth_pubkey_file($this->ssh, $config->username, $config->pubkeyfile, $config->privkeyfile, $config->privkeyfile_pass))
			{
				throw new Exception('Could not connect to SFTP server: invalid username or public/private key file (' . $config->username .
					' - ' . $config->pubkeyfile .
					' - ' . $config->privkeyfile .
					' - ' . $config->privkeyfile_pass .
					')'
				);
			}

		}
		else
		{
			if (!@ssh2_auth_password($this->ssh, $config->username, $config->password))
			{
				throw new Exception('Could not connect to SFTP server: invalid username or password (' . $config->username . ':' . $config->password . ')');
			}
		}


		$this->fp = ssh2_sftp($this->ssh);

		if ($this->fp === false)
		{
			throw new Exception('Could not connect to SFTP server: no SFTP support on this SSH server');
		}

		if (!@ssh2_sftp_stat($this->fp, $config->directory))
		{
			throw new Exception('Could not connect to SFTP server: invalid directory (' . $config->directory . ')');
		}
	}

	public function upload($sourcePath, $destPath)
	{
		$dir = dirname($destPath);
		$this->chdir($dir);

		$realdir = substr($this->config->directory, -1) == '/' ? substr($this->config->directory, 0, -1) : $this->config->directory;
		$realdir .= '/' . $dir;
		$realdir  = substr($realdir, 0, 1) == '/' ? $realdir : '/' . $realdir;
		$realname = $realdir . '/' . basename($destPath);

		$fp = @fopen("ssh2.sftp://{$this->fp}$realname", 'w');
		if ($fp === false)
		{
			throw new Exception("Could not open remote file $realname for writing");
		}
		$localfp = @fopen($sourcePath, 'rb');
		if ($localfp === false)
		{
			throw new Exception("Could not open local file $sourceName for reading");
		}

		$res = true;
		while (!feof($localfp) && ($res !== false))
		{
			$buffer = @fread($localfp, 524288);
			$res    = @fwrite($fp, $buffer);
		}

		@fclose($fp);
		@fclose($localfp);

		if (!$res)
		{
			// If the file was unreadable, just skip it...
			if (is_readable($sourceName))
			{
				throw new Exception('Uploading ' . $targetName . ' has failed.');
			}
			else
			{
				throw new Exception('Uploading ' . $targetName . ' has failed because the file is unreadable.');
			}
		}
	}

	private function chdir($dir)
	{
		$dir = ltrim($dir, '/');
		if (empty($dir))
		{
			return;
		}

		$realdir = substr($this->config->directory, -1) == '/' ? substr($this->config->directory, 0, -1) : $this->config->directory;
		$realdir .= '/' . $dir;
		$realdir = substr($realdir, 0, 1) == '/' ? $realdir : '/' . $realdir;

		$result = @ssh2_sftp_stat($this->fp, $realdir);
		if ($result === false)
		{
			// The directory doesn't exist, let's try to create it...
			if ($this->makeDirectory($dir))
			{
				;
			}
			// After creating it, change into it
			$result = @ssh2_sftp_stat($this->fp, $realdir);
		}

		if (!$result)
		{
			throw new Exception("Cannot change into $realdir directory");
		}

		return true;
	}

	private function makeDirectory($dir)
	{
		$alldirs     = explode('/', $dir);
		$previousDir = substr($this->config->directory, -1) == '/' ? substr($this->config->directory, 0, -1) : $this->config->directory;
		$previousDir = substr($previousDir, 0, 1) == '/' ? $previousDir : '/' . $previousDir;

		foreach ($alldirs as $curdir)
		{
			$check = $previousDir . '/' . $curdir;
			if (!@ssh2_sftp_stat($this->fp, $check))
			{
				if (@ssh2_sftp_mkdir($this->fp, $check, 0755, true) === false)
				{
					throw new Exception('Could not create directory ' . $check);
				}
			}
			$previousDir = $check;
		}

		return true;
	}
}
