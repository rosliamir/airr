<?php

namespace App\Exceptions;

use RuntimeException;

// M4 (FR-M4.9) — thrown when the SqlGuardrail rejects a generated SQL statement.
class SqlGuardrailException extends RuntimeException {}
