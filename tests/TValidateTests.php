<?php

/**
 * Created by PhpStorm.
 * User: george
 * Date: 9/30/17
 * Time: 12:09 AM
 */
class TValidateTests extends \Tests\TestCase
{

    /**
     * simply try to use trait and validate model
     */
    public function testValidationRules()
    {
        $model = new MockModel();
        $this->assertFalse($model->validate());
    }

    public function testScenarios()
    {

    }

    public function testErrorMessageBox()
    {

    }

    public function testValidateRelations()
    {

    }
}

class MockModel extends \Illuminate\Database\Eloquent\Model{
    use \Jorjsmile\LaravelModelValidate\TValidate;

    public $fillable = ['id', 'name', 'position'];
    /**
     * Should return an array of rules for current
     * @return array
     */
    protected function getValidationRules(): array
    {
        return [
              'id' => [["integer"], ["required", "scenario"=>["update"]]],
              'name' => [["string"]], ["required"],
              "position" => [["integer"]]
        ];
    }
}