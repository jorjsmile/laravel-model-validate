<?php

/**
 * Created by PhpStorm.
 * User: george
 * Date: 9/30/17
 * Time: 12:09 AM
 */
class TValidateTests extends \Orchestra\Testbench\TestCase
{
    /**
     * simply try to use trait and validate model
     */
    public function testValidationRules()
    {
        $model = new MockModel();
        $this->assertFalse($model->validate()); //should fail
        $this->assertTrue($model->attributeHasErrors("name"));

        $model->position = "nope"; //force position to non-integer
        $this->assertFalse($model->validate()); //should fail
        $this->assertTrue($model->attributeHasErrors("position"));

    }

    public function testScenarios()
    {
        $model = new MockModel();

        //@test provideDefaultScenario
        $model->exists = true; //if no scenario provided, for exists would be associated update  scenario
        $this->assertFalse($model->validate()); //id should fail
        $this->assertTrue($model->attributeHasErrors("id"));

        $model->exists = false; //turn off scenario auto detection
        $model->setScenario("update"); //set it explicitly
        $this->assertTrue($model->isScenario("update"));
        $this->assertFalse($model->validate()); //id should fail
        $this->assertTrue($model->attributeHasErrors("id"));

        $model->resetScenario();
        $this->assertTrue($model->isScenario(""));


        //@test getScenarioRules
        $rules = $model->getScenarioRules("id"); //get common
        $this->assertContains(["integer"], $rules);

        $rules = $model->getScenarioRules("id", "update"); //get default
        $this->assertContains(["integer", "required"], $rules);
    }

    public function testFields()
    {
        $model = new MockModel();
        $this->assertTrue(
            $model->isRequired("name")
        );

        $model->validate();

        $this->assertTrue(
            $model->attributeHasErrors("name")
        );

        $this->assertContains("required", implode(";", $model->getAttributeError("name")));
    }

    public function testErrorMessageBox()
    {
        $model = new MockModel();
        $model->validate();

        $errors = $model->getErrors();
        $this->assertInstanceOf(\Illuminate\Support\MessageBag::class, $errors);
        $this->assertNotEmpty($errors->all());

        $model->flushErrors();
        $this->assertEmpty($model->getErrors()->all());

        $errors = new \Illuminate\Support\MessageBag([
            "system" => [
                "Wrong system type provided"
            ]
        ]);
        $model->setErrors($errors);
        $this->assertTrue($model->attributeHasErrors("system"));
        $this->assertNotEmpty($model->getErrors()->all());

        $model->addError("name", "System failure");
        $this->assertTrue($model->attributeHasErrors("name"));



    }

    public function testValidateRelations()
    {
        $model = new MockModel();
        $model->setScenario("relations");

        //validate belongsTo
        $model->setRelation("belongsToRelation", new MockRelationModel());
        $this->assertFalse($model->validate());
        $this->assertTrue($model->belongsToRelation->hasErrors());
        $this->assertTrue($model->attributeHasErrors("belongsToRelation::name"));
        $this->assertTrue(!$model->attributeHasErrors("mock_relation_id"));

        //validate hasOne
        $model->setRelation("belongsToRelation", null);
        $model->setRelation("hasOneRelation", new MockRelationModel());

        $this->assertFalse($model->validate());
        $this->assertTrue($model->hasOneRelation->hasErrors());
        $this->assertTrue($model->attributeHasErrors("hasOneRelation::name"));
        $this->assertTrue(!$model->hasOneRelation->attributeHasErrors("mock_model_id"));

        //validate hasMany
        $model->setRelation("hasOneRelation", null);
        $model->setRelation("hasManyRelation",new \Illuminate\Database\Eloquent\Collection([new MockRelationModel()]));
        $this->assertFalse($model->validate());
        $this->assertTrue($model->hasManyRelation->first()->hasErrors());
        $this->assertTrue(!$model->hasManyRelation->first()->attributeHasErrors("mock_model_id"));
        $this->assertTrue($model->attributeHasErrors("hasManyRelation[0]::name"));
//        dd($model->getErrors());

        //validate ManyMany
        $model->setRelation("hasManyRelation", null);
        $model->setRelation("belongsToManyRelation",new \Illuminate\Database\Eloquent\Collection([new MockRelationModel()]));
        $this->assertFalse($model->validate());
        $this->assertTrue($model->belongsToManyRelation->first()->hasErrors());
    }
}

/**
 * @property \Illuminate\Database\Eloquent\Model|\Jorjsmile\LaravelModelValidate\TValidate belongsToRelation
 * @property \Illuminate\Database\Eloquent\Model|\Jorjsmile\LaravelModelValidate\TValidate hasOneRelation
 * @property \Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model[]|\Jorjsmile\LaravelModelValidate\TValidate hasManyRelation
 * @property \Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model[]|\Jorjsmile\LaravelModelValidate\TValidate belongsToManyRelation
 *
 * Class MockModel
 */
class MockModel extends \Illuminate\Database\Eloquent\Model{
    use Jorjsmile\LaravelModelValidate\TValidate;

    public $fillable = ['id', 'name', 'position', 'mock_relation_id'];
    /**
     * Should return an array of rules for current
     * @return array
     */
    protected function getValidationRules(): array
    {
        if($this->isScenario("relations")){
            return [
                'id' => [["integer"], ["required"]],
                'mock_relation_id' => [["integer"], ["required"]],
            ];
        }

        return [
              'id' => [["integer"], ["required", "scenario"=>["update"]]],
              'name' => [["string"], ["required"]],
              "position" => [["integer"]]
        ];
    }

    public function belongsToRelation()
    {
        return $this->belongsTo(MockRelationModel::class, "mock_relation_id");
    }

    public function hasOneRelation()
    {
        return $this->hasOne( MockRelationModel::class, "mock_model_id");
    }

    public function hasManyRelation()
    {
        return $this->hasMany( MockRelationModel::class, "mock_model_id");
    }

    public function belongsToManyRelation()
    {
        return $this->belongsToMany( MockRelationModel::class, "mock_relation_mock_model", "mock_model_id", "mock_relation_id" );
    }
}

class MockRelationModel extends \Illuminate\Database\Eloquent\Model{
    use Jorjsmile\LaravelModelValidate\TValidate;

    protected $fillable = ["id", "mock_model_id"];

    /**
     * Should return an array of rules for current model
     * e.g. return [ 'name' => [['required'],['string']], 'id' =>[['integer']] ]
     * Re
     * @return array
     */
    protected function getValidationRules(): array
    {
        return [
            'id' => [["integer"], ["required"]],
            'mock_model_id' => [["integer"], ["required"]],
            'name' => [["string"], ["required"]]
        ];
    }
}

