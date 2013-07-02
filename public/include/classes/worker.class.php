<?php

// Make sure we are called from index.php
if (!defined('SECURITY'))
  die('Hacking attempt');

class Worker {
  private $sError = '';
  private $table = 'pool_worker';

  public function __construct($debug, $mysqli, $user, $share, $config) {
    $this->debug = $debug;
    $this->mysqli = $mysqli;
    $this->user = $user;
    $this->share = $share;
    $this->config = $config;
    $this->debug->append("Instantiated Worker class", 2);
  }

  // get and set methods
  private function setErrorMessage($msg) {
    $this->sError = $msg;
  }
  public function getError() {
    return $this->sError;
  }

  private function checkStmt($bState) {
    if ($bState ===! true) {
      $this->debug->append("Failed to prepare statement: " . $this->mysqli->error);
      $this->setErrorMessage('Internal application Error');
      return false;
    }
    return true;
  }

  /**
   * Update worker list for a user
   * @param account_id int User ID
   * @param data array All workers and their settings
   * @return bool
   **/
  public function updateWorkers($account_id, $data) {
    $this->debug->append("STA " . __METHOD__, 4);
    if (!is_array($data)) {
      $this->setErrorMessage('No workers to update');
      return false;
    }
    $username = $this->user->getUserName($account_id);
    $iFailed = 0;
    foreach ($data as $key => $value) {
      // Prefix the WebUser to Worker name
      $value['username'] = "$username." . $value['username'];
      $stmt = $this->mysqli->prepare("UPDATE $this->table SET password = ?, username = ?, monitor = ? WHERE account_id = ? AND id = ?");
      if ( ! ( $this->checkStmt($stmt) && $stmt->bind_param('ssiii', $value['password'], $value['username'], $value['monitor'], $account_id, $key) && $stmt->execute()) )
        $iFailed++;
    }
    if ($iFailed == 0)
      return true;
    // Catchall
    $this->setErrorMessage('Failed to update ' . $iFailed . ' worker.');
    return false;
  }

  /**
   * Fetch all IDLE workers that have monitoring enabled
   * @param none
   * @return data array Workers in IDLE state and monitoring enabled
   **/
  public function getAllIdleWorkers() {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT account_id, id, username
      FROM " . $this->table . "
      WHERE monitor = 1 AND ( SELECT SIGN(COUNT(id)) FROM " . $this->share->getTableName() . " WHERE username = $this->table.username AND time > DATE_SUB(now(), INTERVAL 10 MINUTE)) = 0");

    if ($this->checkStmt($stmt) && $stmt->execute() && $result = $stmt->get_result())
      return $result->fetch_all(MYSQLI_ASSOC);
    // Catchall
    $this->setErrorMessage("Unable to fetch IDLE, monitored workers");
    echo $this->mysqli->error;
    return false;
  }

  /**
   * Fetch a specific worker and its status
   * @param id int Worker ID
   * @return mixed array Worker details
   **/
  public function getWorker($id) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
       SELECT id, username, password, monitor,
       ( SELECT SIGN(COUNT(id)) FROM " . $this->share->getTableName() . " WHERE username = $this->table.username AND time > DATE_SUB(now(), INTERVAL 10 MINUTE)) AS active,
       ( SELECT ROUND(COUNT(id) * POW(2, " . $this->config['difficulty'] . ")/600/1000) FROM " . $this->share->getTableName() . " WHERE username = $this->table.username AND time > DATE_SUB(now(), INTERVAL 10 MINUTE)) AS hashrate
       FROM $this->table
       WHERE id = ?
       ");
    if ($this->checkStmt($stmt) && $stmt->bind_param('i', $id) && $stmt->execute() && $result = $stmt->get_result())
      return $result->fetch_assoc();
    // Catchall
    echo $this->mysqli->error;
    return false;
  }

  /**
   * Fetch all workers for an account
   * @param account_id int User ID
   * @return mixed array Workers and their settings or false
   **/
  public function getWorkers($account_id) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT id, username, password, monitor,
      ( SELECT SIGN(COUNT(id)) FROM " . $this->share->getTableName() . " WHERE our_result = 'Y' AND username = $this->table.username AND time > DATE_SUB(now(), INTERVAL 10 MINUTE)) AS active,
      ( SELECT ROUND(COUNT(id) * POW(2, " . $this->config['difficulty'] . ")/600/1000) FROM " . $this->share->getTableName() . " WHERE our_result = 'Y' AND username = $this->table.username AND time > DATE_SUB(now(), INTERVAL 10 MINUTE)) AS hashrate
      FROM $this->table
      WHERE account_id = ?");
    if ($this->checkStmt($stmt) && $stmt->bind_param('i', $account_id) && $stmt->execute() && $result = $stmt->get_result())
      return $result->fetch_all(MYSQLI_ASSOC);
    // Catchall
    $this->setErrorMessage('Failed to fetch workers for your account');
    $this->debug->append('Fetching workers failed: ' . $this->mysqli->error);
    return false;
  }

  /**
   * Get all currently active workers in the past 10 minutes
   * @param none
   * @return data mixed int count if any workers are active, false otherwise
   **/
  public function getCountAllActiveWorkers() {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("SELECT COUNT(DISTINCT username) AS total FROM "  . $this->share->getTableName() . " WHERE time > DATE_SUB(now(), INTERVAL 10 MINUTE)");
    if ($this->checkStmt($stmt)) {
      if (!$stmt->execute()) {
        return false;
      }
      $result = $stmt->get_result();
      $stmt->close();
      return $result->fetch_object()->total;
    }
    return false;
  }

  /**
   * Add new worker to an existing web account
   * The webuser name is prefixed to the worker name
   * Passwords are plain text for pushpoold
   * @param account_id int User ID
   * @param workerName string Worker name
   * @param workerPassword string Worker password
   * @return bool
   **/
  public function addWorker($account_id, $workerName, $workerPassword) {
    $this->debug->append("STA " . __METHOD__, 4);
    $username = $this->user->getUserName($account_id);
    $workerName = "$username.$workerName";
    $stmt = $this->mysqli->prepare("INSERT INTO $this->table (account_id, username, password) VALUES(?, ?, ?)");
    if ($this->checkStmt($stmt)) {
      $stmt->bind_param('iss', $account_id, $workerName, $workerPassword);
      if (!$stmt->execute()) {
        $this->setErrorMessage( 'Failed to add worker' );
        if ($stmt->sqlstate == '23000') $this->setErrorMessage( 'Worker already exists' );
        return false;
      }
      return true;
    }
    return false;
  }

  /**
   * Delete existing worker from account
   * @param account_id int User ID
   * @param id int Worker ID
   * @return bool
   **/
  public function deleteWorker($account_id, $id) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("DELETE FROM $this->table WHERE account_id = ? AND id = ?");
    if ($this->checkStmt($stmt)) {
      $stmt->bind_param('ii', $account_id, $id);
      if ($stmt->execute() && $stmt->affected_rows == 1) {
        $stmt->close;
        return true;
      } else {
        $this->setErrorMessage( 'Unable to delete worker' );
      }
    }
    return false;
  }
}

$worker = new Worker($debug, $mysqli, $user, $share, $config);
