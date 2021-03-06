<?php

use Scalr\Util\CryptoTool;

class Scalr_SshKey extends Scalr_Model
{
    protected $dbTableName = 'ssh_keys';
    protected $dbPrimaryKey = "id";
    protected $dbMessageKeyNotFound = "SSH key #%s not found in database";

    const TYPE_GLOBAL = 'global';
    const TYPE_USER	  = 'user';

    protected $dbPropertyMap = array(
        'id'			=> 'id',
        'env_id'		=> array('property' => 'envId', 'is_filter' => true),
        'type'			=> array('property' => 'type', 'is_filter' => false),
        'private_key'	=> array('property' => 'privateKeyEnc', 'is_filter' => false),
        'public_key'	=> array('property' => 'publicKeyEnc', 'is_filter' => false),
        'cloud_location'=> array('property' => 'cloudLocation', 'is_filter' => false),
        'farm_id'		=> array('property' => 'farmId', 'is_filter' => false),
        'cloud_key_name'=> array('property' => 'cloudKeyName', 'is_filter' => false),
        'platform'		=> array('property' => 'platform', 'is_filter' => true),
    );

    public
        $id,
        $clientId,
        $envId,
        $type,
        $cloudPlatform,
        $farmId,
        $cloudKeyName,
        $cloudLocation,
        $platform;

    protected $privateKeyEnc;
    protected $publicKeyEnc;

    /**
     * @var \DBFarm
     */
    private $dbFarm;

    /**
     *
     * @return Scalr_SshKey
     */
    public static function init($className = null) {
        return parent::init();
    }

    /**
     * Gets DBFarm object
     *
     * @return \DBFarm
     */
    public function getFarmObject()
    {
        try {
            if (!$this->dbFarm && !empty($this->farmId))
                $this->dbFarm = \DBFarm::LoadByID($this->farmId);
        } catch (Exception $e) {}

        return $this->dbFarm;
    }

    public function getFingerprint()
    {
        return "ab:ab:ab:ab";
    }

    public function loadGlobalByName($name, $cloudLocation, $envId, $platform)
    {
        $info = $this->db->GetRow("SELECT * FROM ssh_keys WHERE `cloud_key_name`=? AND (`cloud_location`=? || `cloud_location`='') AND `type`=? AND `env_id` = ? AND `platform` = ? LIMIT 1",
            array($name, $cloudLocation, self::TYPE_GLOBAL, $envId, $platform)
        );
        if (!$info)
            return false;
        else
            return parent::loadBy($info);
    }

    public function loadGlobalByFarmId($envId, $farmId, $cloudLocation, $platform)
    {
        $sql = "SELECT * FROM ssh_keys WHERE `env_id` = ? AND (`cloud_location`=? OR `cloud_location` = '') AND `type`=? AND `platform` = ?";
        $params = [$envId, $cloudLocation, self::TYPE_GLOBAL, $platform];

        if ($farmId == 0 || $farmId == NULL) {
            $sql .= ' AND `farm_id` IS NULL';
        } else {
            $sql .= 'AND `farm_id` = ?';
            $params[] = $farmId;
        }
        $sql .= ' LIMIT 1';

        $info = $this->db->GetRow($sql, $params);
        if (!$info)
            return false;
        else
            return parent::loadBy($info);
    }

    public function getPuttyPrivateKey()
    {
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );

        $pemPrivateKey = tempnam("/tmp", "SSHPEM");
        @file_put_contents($pemPrivateKey, $this->getPrivate());
        @chmod($pemPrivateKey, 0600);

        $ppkPrivateKey = tempnam("/tmp", "SSHPPK");

        $pipes = array();
        $process = @proc_open("puttygen {$pemPrivateKey} -o {$ppkPrivateKey}", $descriptorspec, $pipes);
        if (@is_resource($process)) {

            @fclose($pipes[0]);

            stream_get_contents($pipes[1]);

            fclose($pipes[1]);
            fclose($pipes[2]);
        }

        $retval = file_get_contents($ppkPrivateKey);

        @unlink($pemPrivateKey);
        @unlink($ppkPrivateKey);

        return $retval;
    }

    private function getSshKeygenValue($args, $tmpFileContents, $readTmpFile = false)
    {
        $descriptorspec = array(
           0 => array("pipe", "r"),
           1 => array("pipe", "w"),
           2 => array("pipe", "w")
        );

        $filePath = CACHEPATH . "/_tmp." . CryptoTool::hash($tmpFileContents);

        if (!$readTmpFile)
        {
            @file_put_contents($filePath, $tmpFileContents);
            @chmod($filePath, 0600);
        }

        $pipes = array();
        $process = @proc_open("/usr/bin/ssh-keygen -f {$filePath} {$args}", $descriptorspec, $pipes);
        if (@is_resource($process)) {

            @fclose($pipes[0]);

            $retval = trim(stream_get_contents($pipes[1]));

            fclose($pipes[1]);
            fclose($pipes[2]);
        }

        if ($readTmpFile)
            $retval = file_get_contents($filePath);

        @unlink($filePath);

        return $retval;
    }

    public function generateKeypair()
    {
        $private_key = $this->getSshKeygenValue("-t dsa -q -P ''", "", true);
        $this->setPrivate($private_key);
        $this->setPublic($this->generatePublicKey());
        return array('private' => $private_key, 'public' => $this->getPublic());
    }

    public function generatePublicKey()
    {
        if (!$this->getPrivate())
            throw new Exception("Public key cannot be generated without private key");

        $pub_key = $this->getSshKeygenValue("-y", $this->getPrivate());

        return $pub_key;
    }

    public function setPrivate($key)
    {
        $this->privateKeyEnc = $this->getCrypto()->encrypt($key);
    }

    public function setPublic($key)
    {
        $this->publicKeyEnc = $this->getCrypto()->encrypt($key);
    }

    public function getPrivate()
    {
        return $this->getCrypto()->decrypt($this->privateKeyEnc);
    }

    public function getPublic()
    {
        return $this->getCrypto()->decrypt($this->publicKeyEnc);
    }
}
