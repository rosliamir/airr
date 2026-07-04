<?php

namespace App\Services;

use InvalidArgumentException;

// Restricted arithmetic evaluator for calculation constants — deliberately NOT
// eval()-based. Only numeric literals, +, -, *, /, and parentheses are accepted;
// anything else (letters, function calls, etc.) throws rather than executing.
class SafeArithmeticEvaluator
{
    private string $expr;
    private int $pos = 0;

    public function evaluate(string $expression): float|int
    {
        $this->expr = preg_replace('/\s+/', '', $expression) ?? '';
        $this->pos = 0;

        if ($this->expr === '' || ! preg_match('/^[0-9+\-*\/().]+$/', $this->expr)) {
            throw new InvalidArgumentException("Formula must be a plain arithmetic expression after resolving tokens: [{$expression}]");
        }

        $result = $this->parseExpression();

        if ($this->pos < strlen($this->expr)) {
            throw new InvalidArgumentException('Unexpected trailing characters in formula.');
        }

        return $result;
    }

    private function parseExpression(): float|int
    {
        $value = $this->parseTerm();

        while ($this->pos < strlen($this->expr) && in_array($this->expr[$this->pos], ['+', '-'], true)) {
            $op = $this->expr[$this->pos++];
            $rhs = $this->parseTerm();
            $value = $op === '+' ? $value + $rhs : $value - $rhs;
        }

        return $value;
    }

    private function parseTerm(): float|int
    {
        $value = $this->parseFactor();

        while ($this->pos < strlen($this->expr) && in_array($this->expr[$this->pos], ['*', '/'], true)) {
            $op = $this->expr[$this->pos++];
            $rhs = $this->parseFactor();
            if ($op === '/') {
                if ($rhs == 0) {
                    throw new InvalidArgumentException('Division by zero in formula.');
                }
                $value = $value / $rhs;
            } else {
                $value *= $rhs;
            }
        }

        return $value;
    }

    private function parseFactor(): float|int
    {
        if ($this->pos < strlen($this->expr) && $this->expr[$this->pos] === '-') {
            $this->pos++;

            return -$this->parseFactor();
        }

        if ($this->pos < strlen($this->expr) && $this->expr[$this->pos] === '(') {
            $this->pos++;
            $value = $this->parseExpression();
            if (($this->expr[$this->pos] ?? null) !== ')') {
                throw new InvalidArgumentException('Unbalanced parentheses in formula.');
            }
            $this->pos++;

            return $value;
        }

        $start = $this->pos;
        while ($this->pos < strlen($this->expr) && preg_match('/[0-9.]/', $this->expr[$this->pos])) {
            $this->pos++;
        }
        if ($this->pos === $start) {
            throw new InvalidArgumentException('Expected a number in formula.');
        }

        return (float) substr($this->expr, $start, $this->pos - $start);
    }
}
