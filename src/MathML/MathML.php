<?php

namespace MercurySolutions\MathML;

use MercurySolutions\MathML\Exception\Exception;
use MercurySolutions\MathML\Operator\IOperator;
use MercurySolutions\MathML\Operator\Operator;

/**
 * The MathML class invoked with a mathML string to be calculated
 * @package MercurySolutions\MathMLBundle
 */
class MathML extends Operator
{

    /**
     * The SimpleXMLElement containing the MathML expression
     * @var \SimpleXMLElement
     */
    private $xml;

    /**
     * @param string $mathML The MathML string to calculate
     */
    public function __construct($mathML)
    {
        $this->xml = new \SimpleXMLElement($mathML);
    }

    /**
     * @param array $parameters The associate array containing the values of the <cn> nodes in the MathML expression
     * @return float
     * @throws \DOMException
     * @throws \Exception
     */
    public function calculate(array $parameters = array())
    {
        if ($this->xml->count() != 1) {
            throw new \DOMException('MathML documentElement should only contain one childNode');
        }

        return parent::calculate($parameters);
    }

    /**
     * @param \SimpleXMLElement $element
     * @return \Closure
     * @throws Exception
     * @throws \Exception
     */
    private function getElementClosure(\SimpleXMLElement $element)
    {
        $type = $element->getName();
        switch ($type) {
            case 'ci':
                return $this->getContentIdentifierClosure($element);

            case 'cn':
                return $this->getContentNumberClosure($element);

            case 'apply':
                return $this->getApplyClosure($element);

            default:
                throw new Exception("Unknown element {$type}.");
        }
    }

    /**
     * @param \SimpleXMLElement $apply
     * @return \Closure
     * @throws \Exception
     */
    private function getApplyClosure(\SimpleXMLElement $apply)
    {
        /** @var \SimpleXMLElement[] $children */
        $children = $apply->children();
        $operator = $children[0]->getName();
        $argumentClosures = array();

        for ($i=1; $i<count($children); $i++) {
            $argumentClosures[] = $this->getElementClosure($children[$i]);
        }

        $class = __NAMESPACE__ . "\\Operator\\Operator" . ucwords($operator);
        if (!class_exists($class)) {
            throw new Exception("MathML operator '{$operator}' is unknown.");
        }

        $operatorInstance = new $class();
        if (!$operatorInstance instanceof IOperator) {
            throw new Exception("MathML operator '{$operator}' should implement ".IOperator::class.".");
        }

        return function ($parameters) use ($operatorInstance, $argumentClosures) {
            $arguments = array();
            foreach ($argumentClosures as $argumentClosure) {
                $arguments[] = $argumentClosure($parameters);
            }
            return $operatorInstance->calculate($arguments);
        };
    }

    /**
     * @param \SimpleXMLElement $ciElement
     * @return \Closure
     * @throws \Exception
     */
    private function getContentIdentifierClosure(\SimpleXMLElement $ciElement)
    {
        // get the parameter key
        $key = (string) $ciElement;

        return function (array $parameters) use ($key) {

            // retrieve the value
            $value = $this->parseContentIdentifier($key, $parameters);

            // do not cast NULL or "" as a float
            if ($value === null || strlen($value) == 0) {
                return null;
            }

            if (!is_float($value) && !is_integer($value)) {
                throw new \Exception("Content Identifier '{$key}' is not numeric, '{$value}' given.");
            }

            return (float) $value;
        };
    }

    /**
     * @param string $name
     * @param array $parameters
     * @return mixed
     */
    private function parseContentIdentifier($name, array $parameters)
    {
        $value = $parameters;
        $keys = array();
        preg_match_all('/([^\[\]]+)/', $name, $keys);
        $keys = array_shift($keys);
        while (count($keys) > 0) {
            $current = array_shift($keys);
            if (!array_key_exists($current, $value)) {
                // throw new UnknownContentIdentifier($name);
                return null;
            }
            $value = $value[$current];
        }
        return $value;
    }

    /**
     * @param \SimpleXMLElement $cnElement
     * @return \Closure
     * @throws \Exception
     */
    private function getContentNumberClosure(\SimpleXMLElement $cnElement)
    {
        $value = (string) $cnElement;
        if (strlen($value)===0) {
            throw new \Exception("Content numbers cannot be empty");
        }

        $value = (float) $value;
        if (!is_numeric($value)) {
            throw new \Exception("Content numbers must be numeric");
        }

        return function () use ($value) {
            return $value;
        };
    }

    /**
     * @return \Closure
     * @throws \Exception
     */
    protected function getClosure()
    {
        $applyElement = $this->xml->apply[0];
        return $this->getApplyClosure($applyElement);
    }

    /**
     * @param array $parameters
     * @return bool
     */
    public function areParametersComplete(array $parameters)
    {
        $cis = $this->xml->xpath('//ci');
        foreach ($cis as $ci) {
            $result = $this->parseContentIdentifier((string) $ci, $parameters);
            if ($result === null) {
                return false;
            }
        }
        return true;
    }
}
