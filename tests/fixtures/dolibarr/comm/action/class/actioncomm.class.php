<?php
class ActionComm
{
    public $id, $entity, $socid, $type_code, $datep, $datef, $fulldayevent;
    public function __construct($db) {}
    public function fetch($id)
    {
        if (!isset($GLOBALS['events'][$id])) { return 0; }
        foreach (get_object_vars($GLOBALS['events'][$id]) as $key => $value) { $this->$key = $value; }
        return 1;
    }
}
