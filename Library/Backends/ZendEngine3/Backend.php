<?php
namespace Zephir\Backends\ZendEngine3;

use Zephir\Variable;
use Zephir\Compiler;
use Zephir\CompilerException;
use Zephir\CompilationContext;
use Zephir\Backends\ZendEngine2\Backend as BackendZendEngine2;
use Zephir\BaseBackend;

class Backend extends BackendZendEngine2
{
    protected $name = 'ZendEngine3';

    public function getVariableCode(Variable $variable)
    {
        return '&' . $variable->getName();
    }

    public function getStringsManager()
    {
        return new StringsManager();
    }

    public function getTypeDefinition($type)
    {
        list ($pointer, $code) = parent::getTypeDefinition($type);
        if ($code == 'zval') {
            return array('', 'zval');
        }
        return array($pointer, $code);
    }

    public function onPreInitVar(CompilationContext $context)
    {
        return "\t" . 'zval *this_ptr = getThis();' . PHP_EOL; //TODO: think about a better way to solve this.
    }

    public function generateInitCode(&$groupVariables, $type, $pointer, Variable $variable)
    {

        $isComplex = ($type == 'variable' || $type == 'string' || $type == 'array' || $type == 'resource' || $type == 'callable' || $type == 'object');
        if ($isComplex) { /* && $variable->mustInitNull() */
            $groupVariables[] = $variable->getName();
            return "\t".'ZVAL_UNDEF(&' . $variable->getName() . ');';
        }

        if ($variable->isLocalOnly()) {
            $groupVariables[] = $variable->getName();
            return;
        }

        if ($variable->isDoublePointer()) {
            if ($variable->mustInitNull()) {
                $groupVariables[] = $pointer . $pointer . $variable->getName() . ' = NULL';
            } else {
                $groupVariables[] = $pointer . $pointer . $variable->getName();
            }
            return;
        }

        $defaultValue = $variable->getDefaultInitValue();
        if ($defaultValue !== null) {
            switch ($type) {
                case 'variable':
                case 'string':
                case 'array':
                case 'resource':
                case 'callable':
                case 'object':
                    $groupVariables[] = $pointer . $variable->getName();
                    break;

                case 'char':
                    if (strlen($defaultValue) > 4) {
                        if (strlen($defaultValue) > 10) {
                            throw new CompilerException("Invalid char literal: '" . substr($defaultValue, 0, 10) . "...'", $variable->getOriginal());
                        } else {
                            throw new CompilerException("Invalid char literal: '" . $defaultValue . "'", $variable->getOriginal());
                        }
                    }
                    /* no break */

                default:
                    $groupVariables[] = $pointer . $variable->getName() . ' = ' . $defaultValue;
                    break;
            }

            return;
        }

        if ($variable->mustInitNull() && $pointer) {
            $groupVariables[] = $pointer . $variable->getName() . ' = NULL';
            return;
        }

        $groupVariables[] = $pointer . $variable->getName();
    }

    /* Assign value to variable */
    public function assignString(Variable $variable, $value, CompilationContext $context, $useCodePrinter = true, $doCopy = null)
    {
        return $this->assignHelper('ZVAL_STRING', '&' . $variable->getName(), $value, $context, $useCodePrinter, null);
    }

    public function assignLong(Variable $variable, $value, CompilationContext $context, $useCodePrinter = true)
    {
        return $this->assignHelper('ZVAL_LONG', '&' . $variable->getName(), $value, $context, $useCodePrinter);
    }

    public function assignDouble(Variable $variable, $value, CompilationContext $context, $useCodePrinter = true)
    {
        return $this->assignHelper('ZVAL_DOUBLE', '&' . $variable->getName(), $value, $context, $useCodePrinter);
    }

    public function assignBool(Variable $variable, $value, CompilationContext $context, $useCodePrinter = true)
    {
        return $this->assignHelper('ZVAL_BOOL', '&' . $variable->getName(), $value, $context, $useCodePrinter);
    }

    public function initArray(Variable $variable, CompilationContext $context, $size = null, $useCodePrinter = true)
    {
        if (!isset($size)) {
            $output = 'array_init(&' . $variable->getName() . ');';
        } else {
            $output = 'zephir_create_array(&' . $variable->getName() . ', ' . $size . ', 0);';
        }
        if ($useCodePrinter) {
            $context->codePrinter->output($output);
        }
        return "\t" . $output;
    }

    public function addArrayEntry(Variable $variable, $key, $value, CompilationContext $context, $useCodePrinter = true)
    {
        $type = null;
        switch ($value->getType()) {
            case 'int':
            case 'uint':
            case 'long':
            case 'ulong':
                $type = 'long';
                break;
            case 'string':
                $type = 'stringl';
                break;
        }
        if (!$type) {
            throw new Exception("Unknown type mapping: " . $type);
        }

        $keyStr = $key->getType() == 'string' ? 'SL("' . $key->getCode() . '")' : $key->getCode();
        $valueStr = $type == 'stringl' ? 'SL("' . $value->getCode()  . '")' : $value->getCode();
        $output = 'add_assoc_' . $type . '_ex(&' . $variable->getName() . ', ' . $keyStr . ', ' . $valueStr . ');';

        if ($useCodePrinter) {
            $context->codePrinter->output($output);
        }
        return $output;
    }

    public function initObject(Variable $variable, $ce, CompilationContext $context, $useCodePrinter = true)
    {
        if (!isset($ce)) {
            $output = 'object_init(&' . $variable->getName() . ');';
        } else {
            $output = 'object_init_ex(&' . $variable->getName() . ', ' . $ce . ');';
        }
        if ($useCodePrinter) {
            $context->codePrinter->output($output);
        }
        return $output;
    }

    public function arrayFetch(Variable $var, Variable $src, $index, $flags, $arrayAccess, CompilationContext $context, $useCodePrinter = true)
    {
        $indexCode = $index->getCode();
        switch ($index->getType()) {
            case 'int':
            case 'uint':
            case 'long':
                $type = 'long';
                break;
            /* Types which map to the same */
            case 'string':
                $indexCode = 'SL("' . $indexCode . '")';
                $type = $index->getType();
                break;
            default:
                throw new CompilerException("Variable type: " . $index->getType() . " cannot be used as array index without cast", $arrayAccess['right']);
        }
        $output = 'zephir_array_fetch_'.$type.'(&' . $var->getName() . ', &' . $src->getName() . ', ' . $indexCode . ', ' . $flags . ', "' . Compiler::getShortUserPath($arrayAccess['file']) . '", ' . $arrayAccess['line'] . ');';
        if ($useCodePrinter) {
            $context->codePrinter->output($output);
        }
        return $output;
    }

    public function arrayUnset(Variable $variable, $exprIndex, $flags, CompilationContext $context)
    {
        $context->headersManager->add('kernel/array');
        switch ($exprIndex->getType()) {
            case 'int':
            case 'uint':
            case 'long':
                $context->codePrinter->output('zephir_array_unset_long(&' . $variable->getName() . ', ' . $exprIndex->getCode() . ', ' . $flags . ');');
                break;

            case 'string':
                $context->codePrinter->output('zephir_array_unset_string(&' . $variable->getName() . ', SL("' . $exprIndex->getCode() . '"), ' . $flags . ');');
                break;

            case 'variable':
                $variableIndex = $context->symbolTable->getVariableForRead($exprIndex->getCode(), $context, $exprIndex->getOriginal());
                switch ($variableIndex->getType()) {
                    case 'int':
                    case 'uint':
                    case 'long':
                        $context->codePrinter->output('zephir_array_unset_long(&' . $variable->getName() . ', ' . $variableIndex->getName() . ', ' . $flags . ');');
                        break;

                    case 'string':
                    case 'variable':
                        $context->codePrinter->output('zephir_array_unset(&' . $variable->getName() . ', ' . $variableIndex->getName() . ', ' . $flags . ');');
                        break;

                    default:
                        throw new CompilerException("Variable type: " . $variableIndex->getType() . " cannot be used as array index without cast", $expression['right']);
                }
                break;

            default:
                throw new CompilerException("Cannot use expression: " . $exprIndex->getType() . " as array index without cast", $expression['right']);
        }
    }

    public function assignArrayMulti(Variable $variable, $symbolVariable, $offsetExprs, CompilationContext $compilationContext)
    {
        $keys = '';
        $offsetItems = array();
        $numberParams = 0;

        foreach ($offsetExprs as $offsetExpr) {
            switch ($offsetExpr->getType()) {
                case 'int':
                case 'uint':
                case 'long':
                case 'ulong':
                    $keys .= 'l';
                    $offsetItems[] = $offsetExpr->getCode();
                    $numberParams++;
                    break;

                case 'string':
                    $keys .= 's';
                    $offsetItems[] = 'SL("' . $offsetExpr->getCode() . '")';
                    $numberParams += 2;
                    break;

                case 'variable':
                    $variableIndex = $compilationContext->symbolTable->getVariableForRead($offsetExpr->getCode(), $compilationContext, $statement);
                    switch ($variableIndex->getType()) {
                        case 'int':
                        case 'uint':
                        case 'long':
                        case 'ulong':
                            $keys .= 'l';
                            $offsetItems[] = $variableIndex->getName();
                            $numberParams++;
                            break;
                        case 'string':
                        case 'variable':
                            $keys .= 'z';
                            $offsetItems[] = '&' . $variableIndex->getName();
                            $numberParams++;
                            break;
                        default:
                            throw new CompilerException("Variable: " . $variableIndex->getType() . " cannot be used as array index", $statement);
                    }
                    break;

                default:
                    throw new CompilerException("Value: " . $offsetExpr->getType() . " cannot be used as array index", $statement);
            }
        }

        $compilationContext->codePrinter->output('zephir_array_update_multi(&' . $variable->getName() . ', &' . $symbolVariable->getName() . ' TSRMLS_CC, SL("' . $keys . '"), ' . $numberParams . ', ' . join(', ', $offsetItems) . ');');
    }

    public function fetchClass(Variable $zendClassEntry, $className, $guarded, CompilationContext $context)
    {
        $context->headersManager->add('kernel/object');
        if ($guarded) {
            $context->codePrinter->output('if (!' . $zendClassEntry->getName() . ') {');
        }
        $context->codePrinter->output("\t" . $zendClassEntry->getName() . ' = zephir_fetch_class_str_ex(' . $className . ', ZEND_FETCH_CLASS_AUTO);');
        if ($guarded) {
            $context->codePrinter->output('}');
        }
    }

    public function fetchProperty(Variable $symbolVariable, Variable $variableVariable, $property, $readOnly, CompilationContext $context, $useOptimized = false)
    {
        //TODO: maybe optimizations (read_nproperty/quick) for thisScope access in NG (as in ZE2 - if necessary)
        $context->codePrinter->output('zephir_read_property(&' . $symbolVariable->getName() . ', ' . $variableVariable->getName() . ', SL("' . $property . '"), PH_NOISY_CC);');
    }

    public function fetchStaticProperty(Variable $symbolVariable, $classDefinition, $property, $readOnly, CompilationContext $context)
    {
        //TODO: maybe optimizations aswell as above
        $context->codePrinter->output('zephir_read_static_property_ce(&' . $symbolVariable->getName() . ', ' . $classDefinition->getClassEntry() . ', SL("' . $property . '") TSRMLS_CC);');
    }

    public function updateProperty(Variable $symbolVariable, $propertyName, Variable $value, CompilationContext $context)
    {
        //TODO: maybe optimizations aswell as above
        $context->codePrinter->output('zephir_update_property_zval(' . $symbolVariable->getName() . ', SL("' . $propertyName . '"), &' . $value->getName() . ');');
    }

    public function checkConstructor(Variable $var, CompilationContext $context)
    {
        $context->codePrinter->output('if (zephir_has_constructor(&' . $var->getName() . ')) {');
    }

    public function callMethod($symbolVariable, Variable $variable, $methodName, $cachePointer, $params, CompilationContext $context)
    {
        $paramStr = $params != null ? ', ' . join(', ', $params) : '';
        if (!isset($symbolVariable)) {
            $context->codePrinter->output('ZEPHIR_CALL_METHOD(NULL, &' . $variable->getName() . ', "' . $methodName . '", ' . $cachePointer . $paramStr . ');');
        } else if ($symbolVariable->getName() == 'return_value') {
            $context->codePrinter->output('ZEPHIR_RETURN_CALL_METHOD(&' . $variable->getName() . ', "' . $methodName . '", ' . $cachePointer . $paramStr . ');');
        } else {
            $context->codePrinter->output('ZEPHIR_CALL_METHOD(&' . $symbolVariable->getName() . ', &' . $variable->getName() . ', "' . $methodName . '", ' . $cachePointer . $paramStr . ');');
        }
    }

    public function zvalOperator($zvalOperator, Variable $expected, Variable $variableLeft, Variable $variableRight, CompilationContext $compilationContext)
    {
        if ($variableLeft->isLocalOnly()) {
            $op1 = '&' . $variableLeft->getName();
        } else {
            $op1 = $variableLeft->getName();
        }

        if ($variableRight->isLocalOnly()) {
            $op2 = '&' . $variableRight->getName();
        } else {
            $op2 = $variableRight->getName();
        }

        $compilationContext->codePrinter->output($zvalOperator . '(' . $expected->getName() . ', &' . $op1 . ', &' . $op2 . ');');
    }
}
