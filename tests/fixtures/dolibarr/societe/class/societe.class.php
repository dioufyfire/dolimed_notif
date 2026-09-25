<?php
class Societe
{
    public $id, $entity, $name, $phone_mobile, $phone, $array_options;
    public function __construct($db) {}
    public function fetch($id)
    {
        if (!isset($GLOBALS['patients'][$id])) { return 0; }
        foreach (get_object_vars($GLOBALS['patients'][$id]) as $key => $value) { $this->$key = $value; }
        return 1;
    }
    public function fetch_optionals() { return 1; }
}
