<?php

defined('SYSPATH') or die('No direct script access.');

class Model_Notam_Category extends Model_Master {

    protected $_db_group = 'site';
    protected $_table_name = 'notam_category';
    protected $_primary_key = 'id';
    protected $_table_columns = array(
        'id' => array('data_type' => 'medint'),
        'name' => array('data_type' => 'varchar'),
        'deleted' => array('data_type' => 'boolean'),
    );
    
    // fields mentioned here can be accessed like properties, but will not be referenced in write operations
    protected $_ignored_columns = array(
    );

    // Belongs to relationships
    protected $_belongs_to = array();
    
    // Has man relationships
    protected $_has_many = array(
        'notams' => array(
            'model' => 'Notam',
            'foreign_key' => 'category_id',
        ),
    );
    
    // Has one relationship
    protected $_has_one = array(
    );
    
    // Validation rules
    public function rules(){
        return array(
            'name' => array(
                array('not_empty'),
            ),
            'description' => array(
                array('not_empty'),
            ),
        );
    }
    
    // Data filters
    public function filters(){
        return array(
            'name' => array(
                array('trim'),
                array(array("UTF8", "clean"), array(":value")),
            ),
            'description' => array(
                array('trim'),
                array(array("UTF8", "clean"), array(":value")),
            ),
        );
    }
}

?>