<?php
namespace Jorjsmile\LaravelModelValidate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Validator;

/**
 * Created by PhpStorm.
 * User: george
 * Date: 9/28/17
 * Time: 9:47 PM
 *
 * @property MessageBag $_errors
 * @property string $_scenario
 *
 * It will bring to your model set of instruments to deal with validations inside of model:
 * - Scenarios
 * - Messages
 * - Events (validating, validated)
 *
 * @mixin Model
*/
trait TValidate
{
    /**
     * List of current model errors
     * @var null|MessageBag $_errors
     */
    protected $_errors = null;

    /**
     * Provide mechanism to unification or division of validation rules
     * According to given scenario, Appropriate rules would be extracted
     *
     * to provide a scenario to a rule use an array of string values.
     * E.g. assume name is required on insert
     *
     * 'name' => [['required', 'scenario' => ['insert']], ...other rules]
     * @var string $_scenario
     */
    protected $_scenario;

    /**
     * Should be or not relation validated.
     * You can pass also an array of desired relation, that should be validated
     *
     * @var bool|array $_validateRelations
     */
    protected $_validateRelations = true;

    /**
     * Should return an array of rules for current model
     * e.g. return [ 'name' => [['required'],['string']], 'id' =>[['integer']] ]
     * Name of the rules you can find in laravel documentation.
     * @return array
     */
    abstract protected function getValidationRules() : array;

    /**
     * @param array $fields
     *
     * @return bool
     */
    public function validate($fields = []) : bool
    {
        if($this->beforeValidate() === false) return false;

        $this->flushErrors();
        $scenario = $this->_scenario?:$this->resolveDefaultScenario();

        if($this->_validateRelations){
            $this->diveDeeper();
        }


        $rules = $this->getScenarioRules( $fields, $scenario );
        $data = [];
        foreach($rules as $field=>$specs){
            $data[$field] = $this->getAttribute($field);
        }

        if(empty($rules))
            $this->setErrors( new MessageBag() ); // return empty errors bag
        else{
            $validator = $this->provideValidator($data, $rules);
            if($validator->fails())
                $this->setErrors( $validator->errors() );
        }


        $this->afterValidate();
        return !$this->hasErrors();
    }

    /**
     * @param array $data
     * @param array $rules
     * @param array $messages
     * @param array $customAttributes
     * @return \Illuminate\Validation\Validator
     */
    protected function provideValidator($data, $rules, $messages=[], $customAttributes=[])
    {
        return \Validator::make($data, $rules, $messages, $customAttributes);
    }

    /**
     * Check if model has errors
     * @return bool
     */
    public function hasErrors() : bool
    {
        return $this->_errors instanceof MessageBag && ($this->_errors->count() !== 0);
    }

    /**
     * Event before model validating
     * @param $callback
     */
    public static function validating($callback)
    {
        self::registerModelEvent("validating", $callback);
    }

    /**
     * Event after  model validated
     * @param $callback
     */
    public static function validated($callback)
    {
        self::registerModelEvent("validated", $callback);
    }

    /**
     * Handle before validate action
     * @return int
     */
    protected function beforeValidate()
    {
        return $this->fireModelEvent("validating");
    }

    /**
     * Handle after validate action
     */
    protected function afterValidate()
    {
        return $this->fireModelEvent("validated");
    }

    /**
     * List of rules for specific scenario
     *
     * @param array $fields
     * @param string $scenario
     * @return array
     */
    public function getScenarioRules($fields = [], $scenario = "")
    {

        $rules = $this->getValidationRules();
        $fields = (array) $fields;

        if(!empty($fields))
            $rules = array_intersect_key($rules, array_flip($fields));

        if(empty($rules)) return [];
        $out = [];
        foreach ($rules as $attr => $attributeRules) {
            foreach ($attributeRules as $r)
                if (!isset($r["scenario"]) || in_array($scenario, $r["scenario"])) {
                    $out[$attr][] = $r[0];
                }
        }

        return $out;
    }

    /**
     * @return mixed
     */
    public function getScenario()
    {
        return $this->_scenario;
    }

    /**
     * @param $name
     * @return bool
     */
    public function isScenario($name)
    {
        return $this->_scenario == $name;
    }


    /**
     * @param mixed $scenario
     * @return static
     */
    public function setScenario($scenario)
    {
        $this->_scenario = $scenario;
        return $this;
    }



    /**
     * Provide default scenario
     * @return string
     */
    public function resolveDefaultScenario()
    {
        return $this->exists? "update" : "insert";
    }

    /**
     * unset scenario
     * @return $this
     */
    public function resetScenario()
    {
        $this->_scenario = "";
        return $this;
    }


    /** ================================= Deeper Validation Coverage ============================================ */

    /**
     * It will try to validate all of currently initialized relations, those of them that has this trait OFC
     */
    public function diveDeeper()
    {
        $relations = array_filter($this->getRelations());

        foreach ($relations as $name => $model) {
            /**
             * @var Relation $relation
             */
            $relation = $this->$name();
            $modelInstance = $relation->getModel();

            if(!$this->hasTValidateTrait($modelInstance))
                continue;

            switch (get_class($relation)) {

                case BelongsTo::class :
                    /**
                     * @var BelongsTo|Relation $relation
                     */
                    $model = $this->validateRelation($name, $relation, $model);

                    $fK = $relation->getForeignKey();
                    if($model->exists && $model->getKey()){
                        $kK = $relation->getOwnerKey();
                        $this->$fK = $model->$kK;
                    }
                    else
                        $this->$fK = -1; //set foreign key to non-existing value for self validation succeed

                    break;

                case HasOne::class :
                    $this->validateRelation($name, $relation, $model);
                    break;

                case HasMany::class :
                case BelongsToMany::class :

                    foreach ($model as $k => $m)
                        $this->validateRelation($name."[$k]", $relation, $m);

                    break;
            }
        }
    }


    /**
     * @param string $name
     * @param Relation $relation
     * @param Model|TValidate $model
     * @return Model
     */
    protected function validateRelation( $name, Relation $relation, Model $model) : Model
    {
        $relClass = get_class($relation);

        if (in_array($relClass, [HasOne::class, HasMany::class])) {
            /**
             * @var HasOne $relation
             */
            $fkName = $relation->getForeignKeyName();
            $pkName = $this->getKeyName();
            if(!($model->$fkName)){ //`required validation` assignment
                //set new one relation or existing one
                $model->$fkName =  $this->$pkName ?? -1;
            }
        }

        if (!$model->validate()) {
            $relationErrors = $model->getErrors()->getMessages();

            foreach ($relationErrors as $field => $error)
                $this->addError($name . "." . $field, $error);//add error

        }

        return $model;
    }


    /**
     * Check whether class uses current trait
     * @param string|object $model
     * @return bool
     */
    public function hasTValidateTrait($model) : bool
    {
        return in_array("TValidate", class_uses($model)) || in_array(TValidate::class, class_uses($model));
    }

    ////////////////////////////////////// Deeper Validation Coverage ///////////////////////////////////////////////


    /** ================================= Controls over ErrorMessageBag ============================================ */
    /**
     *
     * @return MessageBag
     */
    public function getErrors()
    {
        return $this->_errors instanceof MessageBag? $this->_errors : new MessageBag();
    }

    public function setErrors(MessageBag $e)
    {
        if($this->_errors instanceof MessageBag)
            $this->_errors->merge($e);
        else
            $this->_errors = $e;
    }

    public function addError($key, $value)
    {
        if(!$this->_errors instanceof MessageBag)
            $this->_errors = new MessageBag();

        $this->_errors->merge([$key => (array) $value]);
    }

    public function flushErrors()
    {
        $this->_errors = new MessageBag();
    }

    ////////////////////////////////////// Controls over ErrorMessageBag ///////////////////////////////////////////////

    /** ================================= Controls over Attribute errors ============================================ */
    /**
     * returns list of attribute errors
     * @param string $attr name of the attribute
     * @return array
     */
    public function getAttributeError($attr)
    {
        if ($this->getErrors())
            return $this->getErrors()->get($attr);
        return [];
    }

    /**
     * @param string $attr name of the attr
     * @return bool
     */
    public function attributeHasErrors($attr)
    {
        if ($this->getErrors())
            return $this->getErrors()->has($attr);
        return false;
    }

    /**
     * remove all errors from an attribute
     * @param string $attr
     */
    public function attributeRemoveErrors($attr)
    {
        $msgs = $this->getErrors()->getMessages();
        if(isset($msgs[$attr]))
            unset($msgs[$attr]);
        $msgs = array_filter($msgs); //remove null values from array
        $this->flushErrors(); //remove all errors
        $this->setErrors(new MessageBag($msgs));
    }

    /**
     * Check whether for given scenario field is required.
     * The idea can be extended for other validation rules
     * @param $field
     * @return boolean
     */
    public function isRequired($field)
    {
        $rules = $this->getScenarioRules([$field]);

//
        if(empty($rules)) return false;

        //extract only required rules from field rules
        $rules = array_filter($rules[$field], function($v) {
            return preg_match("/^required.*?$/", $v);
        });

        $data = $this->getAttributes();
        unset($data[$field]);

        return \Validator::make($data, [$field => $rules])->errors()->has($field);
    }

    //////////////////////////////////// Controls over Attribute errors ///////////////////////////////////////////////


}
