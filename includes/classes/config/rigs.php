<?php
require_once('abstract.php');
/**
 * Configuring rigs
 *
 * @author Stoyvo
 */

class Config_Rigs extends Config_Abstract {

    protected $_config = 'configs/miners.json';


    /*
     * Specific to class
     */

    protected function add($rig) {

        if (empty($rig['type']) || empty($rig['host']) || empty($rig['port'])) {
            return false;
        }

        $name = (!empty($rig['name']) ? $rig['name'] : $rig['host']);
        if (empty($rig['settings'])) {
            $rig['settings'] = array();
        }

        $class = 'Miners_' . ucwords(strtolower($rig['type']));
        $obj = new $class($rig);
        $this->_objs[] = $obj;
    }

    // validate posted data for rig
    protected function postValidate($dataType, $data) {
        // TO-DO: Rethink this... Maybe some kind of validator class that returns true/false

        if ($dataType == 'details' &&
            (empty($data['ip_address']) || empty($data['port']))
        ) {
            header("HTTP/1.0 406 Not Acceptable"); // not accepted
            return 'Missing ' . (empty($data['ip_address']) ? 'IP Address' : 'Port');
        } else if ($dataType == 'thresholds') {
            if ($data['temps']['enabled'] == 'on' &&
            (empty($data['temps']['warning']) || empty($data['temps']['danger']))
            ) {
                header("HTTP/1.0 406 Not Acceptable"); // not accepted
                return 'Temperature Warning and Danager values must be set!';
            } else if ($data['hwErrors']['enabled'] == 'on' && empty($data['hwErrors']['type'])) {
                header("HTTP/1.0 406 Not Acceptable"); // not accepted
                return 'Hardware errors require a Percent or Integer display type!';
            } else if ($data['hwErrors']['enabled'] == 'on' && $data['hwErrors']['type'] == 'int' &&
                (empty($data['hwErrors']['warning']['int']) || empty($data['hwErrors']['danger']['int']))
            ) {
                header("HTTP/1.0 406 Not Acceptable"); // not accepted
                return 'An integer value must be set!';
            } else if ($data['hwErrors']['enabled'] == 'on' && $data['hwErrors']['type'] == 'percent' &&
                (empty($data['hwErrors']['warning']['percent']) || empty($data['hwErrors']['danger']['percent']))
            ) {
                header("HTTP/1.0 406 Not Acceptable"); // not accepted
                return 'An percent value must be set!';
            } else if (
                ($data['hwErrors']['warning']['percent'] >= $data['hwErrors']['danger']['percent']) ||
                ($data['hwErrors']['warning']['int'] >= $data['hwErrors']['danger']['int']) ||
                ($data['temps']['warning'] >= $data['temps']['danger'])
            ) {
                header("HTTP/1.0 406 Not Acceptable"); // not accepted
                return 'Warning setting <b>cannot</b> be a higher value than your danger setting.';
            }
        } else if ($dataType == 'pools') {
            foreach ($data as $poolData) {
                if (empty($poolData['url'])) {
                    header("HTTP/1.0 406 Not Acceptable"); // not accepted
                    return 'Pool requires a URL to connect to!';
                } else if (empty($poolData['user'])) {
                    header("HTTP/1.0 406 Not Acceptable"); // not accepted
                    return 'Pools require some sort of username. Either an coin address or a username/worker.';
                }
                //  else if (empty($data['new']['password'])) {
                //     header("HTTP/1.0 406 Not Acceptable"); // not accepted
                //     return 'Use atleast 1 character for a password. For example: "x".';
                // }
            }
        }

        return true;
    }
    protected function isUnique($dataType, $data) {
        if ($dataType == 'details') {
            foreach ($this->_data as $rig) {
                if ($data['ip_address'] == $rig['host'] && $data['port'] == $rig['port']) {
                    header("HTTP/1.0 409 Conflict"); // conflict
                    return 'This rig already exists as ' . (!empty($rig['name']) ? $rig['name'] : $rig['host'].':'.$rig['port']);
                }
            }
        }

        return true;
    }

    public function create() {
        $isValid = $this->postValidate(array('details' => $_POST));
        if ($isValid !== true) {
            return $isValid;
        }

        $isUnique = $this->isUnique(array('details' => $_POST));
        if ($isUnique !== true) {
            return $isUnique;
        }

        $this->_data[] = array(
            'name' => (!empty($_POST['label']) ? $_POST['label'] : $_POST['ip_address']),
            'type' => 'cgminer',
            'host' => $_POST['ip_address'],
            'port' => $_POST['port'],
            'settings' => array(
                'algorithm' => $_POST['algorithm']
            ),
        );

        return $this->write();
    }

    public function update() {
        $id = intval($_GET['id'])-1;

        foreach ($_POST as $dataType => $data) {
            $name = 'update' . ucfirst($dataType);
            return $this->$name($id, $dataType, $data);
        }
    }

    private function updateDetails($id, $dataType, $data) {
        $isValid = $this->postValidate($dataType, $data);
        if ($isValid !== true) {
            return $isValid;
        }

        $rig = array(
            'name' => (!empty($data['label']) ? $data['label'] : $data['ip_address']),
            'type' => 'cgminer',
            'host' => $data['ip_address'],
            'port' => $data['port'],
            'settings' => array(
                'algorithm' => $data['algorithm']
            ),
        );

        $this->_data[$id] = array_replace_recursive($this->_data[$id], $rig);

        $this->write();

        return true;
    }

    private function updateThresholds($id, $dataType, $data) {
        // Some data scrubbing
        $data['hwErrors']['warning']['percent'] = (float) preg_replace('/[^0-9.]*/', '', $data['hwErrors']['warning']['percent']);
        $data['hwErrors']['danger']['percent'] = (float) preg_replace('/[^0-9.]*/', '', $data['hwErrors']['danger']['percent']);
        if (!isset($data['temps']['enabled'])) {
            $data['temps']['enabled'] = 0;
        }
        if (!isset($data['hwErrors']['enabled'])) {
            $data['hwErrors']['enabled'] = 0;
        }

        // Validate post
        $isValid = $this->postValidate($dataType, $data);
        if ($isValid !== true) {
            return $isValid;
        }

        $this->_data[$id]['settings'] = array_replace_recursive($this->_data[$id]['settings'], $data);

        $this->write();

        return true;
    }

    private function updatePools($id, $dataType, $data) {
        // Validate post
        $isValid = $this->postValidate($dataType, $data);
        if ($isValid !== true) {
            return $isValid;
        }

        // Collection of pool data based on what we're looking for
        $addedPools = array();
        $removedPools = array();
        $rigPools = $this->_objs[0]->pools();

        // Look for new pools in the post
        foreach ($data as $postedPool) {
            $added = true;
            foreach ($rigPools as $rigPool) {
                if ($postedPool['url'] == $rigPool['url'] && $postedPool['user'] == $rigPool['user']) {
                    $added = false;
                }
            }
            if ($added) {
                if (empty($postedPool['password'])) {
                    $postedPool['password'] = 'x';
                }
                $addedPools[] = $postedPool;
            }
        }

        // Look for removed pools in the post
        foreach ($rigPools as $rigPoolId => $rigPool) {
            $removed = true;
            foreach ($data as $postedPool) {
                if ($postedPool['url'] == $rigPool['url'] && $postedPool['user'] == $rigPool['user']) {
                    $removed = false;
                }
            }
            if ($removed) {
                $removedPools[] = $rigPoolId;
            }
        }

        // Send new pool to the rig
        foreach ($addedPools as $pool) {
            $this->_objs[0]->addPool($pool);
        }

        // Remove the pools that were deleted
        foreach ($removedPools as $poolId) {
            $this->_objs[0]->removePool($poolId);
        }

        // TO-DO: Prioritize pools


        // TO-DO: Eventually we will want some kind of profile... For now, just apply the pool to the rig


        return true;
    }

}
