<?php

namespace Peralta\AgentKit\ErrorRecovery\Enums;

enum ErrorType: string
{
    case NETWORK_TIMEOUT = 'NETWORK_TIMEOUT';
    case RATE_LIMIT = 'RATE_LIMIT';
    case INVALID_REQUEST = 'INVALID_REQUEST';
    case AUTH_ERROR = 'AUTH_ERROR';
    case SERVER_ERROR = 'SERVER_ERROR';
}
