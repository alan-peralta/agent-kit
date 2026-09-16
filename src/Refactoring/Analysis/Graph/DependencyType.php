<?php

namespace Peralta\AgentKit\Refactoring\Analysis\Graph;

enum DependencyType: string
{
    case CONSTRUCTOR_INJECTION = 'constructor_injection';
    case METHOD_PARAMETER = 'method_parameter';
    case RETURN_TYPE = 'return_type';
    case PROPERTY_TYPE = 'property_type';
    case EXTENDS = 'extends';
    case IMPLEMENTS = 'implements';
    case TRAIT = 'trait';
    case INSTANTIATION = 'instantiation';
    case STATIC_CALL = 'static_call';
    case METHOD_CALL = 'method_call';
    case CLASS_CONSTANT = 'class_constant';
    case ATTRIBUTE = 'attribute';
    case FACADE = 'facade';
    case EVENT = 'event';
}
