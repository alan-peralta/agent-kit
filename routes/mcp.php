<?php

use Illuminate\Support\Facades\Route;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\HttpTransportOptions;
use Peralta\AgentKit\Refactoring\Mcp\Transport\Http\McpHttpController;

Route::match(
    ['GET', 'POST', 'DELETE', 'OPTIONS'],
    HttpTransportOptions::normalizePath((string) config('agent-kit.mcp.http.path', '/mcp')),
    McpHttpController::class,
)->name('agent-kit.mcp');
