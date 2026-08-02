<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Tool
{
    public static function executeToolCalls(array $toolCalls): array
    {
        if (empty($toolCalls)) {
            return [];
        }

        $toolMessages = [];
        $updates = []; // collect data for mass update

        foreach ($toolCalls as $call) {
            $id      = $call['id'] ?? null;
            $name    = $call['function']['name'] ?? null;
            $rawArgs = $call['function']['arguments'] ?? '{}';
            $args    = json_decode($rawArgs, true) ?? [];

            $content = Tool::run($name, $args);

            $toolMessages[] = [
                'role'         => 'tool',
                'tool_call_id' => $id,
                'content'      => $content,
            ];

            $updates[] = [
                'id'       => $id,
                'response' => $content,
            ];
        }

        DB::transaction(function () use ($updates) {
            echo "\n\n\n";
            echo json_encode($updates);
            echo "\n\n\n";
            foreach ($updates as $update) {
                DB::table('tool_calls')->where('tool_call_id', $update['id'])
                    ->update([
                        'response' => $update['response'],
                    ]);
            }
        });

        return $toolMessages;
    }

    public static function list()
    {
        return [
            // [
            //     'type'     => 'function',
            //     'function' => [
            //         'name'        => 'get_weather',
            //         'description' => 'Get current weather for a location',
            //         'parameters'  => [
            //             'type'       => 'object',
            //             'properties'  => [
            //                 'location' => [
            //                     'type'        => 'string',
            //                     'description' => 'City name',
            //                 ],
            //             ],
            //             'required' => ['location'],
            //         ],
            //         'callback' => function (array $args): string {
            //             $location = $args['location'];
            //             return ToolCallbacks::weather($location);
            //         },
            //     ],
            // ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'datetime_now',
                    'description' => 'Get datetime right now',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties'  => [],
                    ],
                    'callback' => function (array $args): string {
                        return ToolCallbacks::dateTimeNow();
                    },
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_available_slots',
                    'description' => 'Fetch all available time slots that can accommodate an appointment of the given duration. Results are ordered from earliest to latest.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties'  => [
                            'duration_minutes' => [
                                'type'        => 'integer',
                                'description' => 'Duration of the appointment in minutes.',
                            ],
                            'date' => [
                                'type'        => 'string',
                                'description' => 'Date to check (YYYY-MM-DD). If omitted, today\'s date is used.',
                            ],
                        ],
                        'required' => ['duration_minutes'],
                    ],
                    'callback' => function (array $args): string {
                        $date = $args['date'] ?? date('YYYY-MM-DD');
                        $duration_minutes = $args['duration_minutes'];
                        return ToolCallbacks::getAvailableSlots($date, $duration_minutes);
                    },
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'book_appointment',
                    'description' => 'Create a new appointment in a previously identified time slot.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties'  => [
                            'start_time' => [
                                'type'        => 'string',
                                'description' => 'Start time of the slot (ISO 8601 datetime, e.g. 2026-07-31T14:00:00).',
                            ],
                            'duration_minutes' => [
                                'type'        => 'integer',
                                'description' => 'Duration of the appointment in minutes (must match the slot).',
                            ],
                            'title' => [
                                'type'        => 'string',
                                'description' => 'Title or brief description of the appointment.',
                            ],
                            'customer_name' => [
                                'type'        => 'string',
                                'description' => 'Name of the client or customer.',
                            ],
                        ],
                        'required' => ['start_time', 'duration_minutes', 'title', 'customer_name'],
                    ],
                    'callback' => function (array $args): string {
                        $start_time = $args['start_time'];
                        $duration_minutes = $args['duration_minutes'];
                        $title = $args['title'];
                        $customer_name = $args['customer_name'];
                        return ToolCallbacks::bookAppointment($start_time, $duration_minutes, $title, $customer_name);
                    },
                ],
            ],
        ];
    }

    public static function run(string $name, array $arguments)
    {
        if (!strlen($name) > 0) {
            return "Error: Tool name is required.";
        }

        $availableTools = [];
        foreach (self::list() as $tool) {
            $availableTools[$tool['function']['name']] = $tool['function'] ?? [];
        }

        if (!isset($availableTools[$name])) {
            return "Error: Unknown tool $name";
        }

        $schema = $availableTools[$name]['parameters'];

        if (!empty($schema)) {
            $errors = self::validateSchema($arguments, $schema);
            if (!empty($errors)) {
                return "Error: Argument validation failed: " . implode("; ", $errors);
            }
        }

        $tool_return = $availableTools[$name]['callback']($arguments);
        if (!is_string($tool_return)) {
            return sprintf(
                'Error: Tool "%s" must return a string, got %s.',
                $name,
                gettype($tool_return)
            );
        }

        return $tool_return;
    }

    /**
     * Recursively validate a value against a JSON Schema (draft‑7 subset).
     *
     * @param mixed  $value  The value to validate.
     * @param array  $schema The schema definition.
     *
     * @return array An array of error messages (empty if valid).
     */
    private static function validateSchema($value, array $schema): array
    {
        $errors = [];

        // --- Type validation ---
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $valid = false;
            foreach ($types as $type) {
                if (self::checkType($value, $type)) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                $actual = self::describeType($value);
                $expected = implode('|', $types);
                $errors[] = "expected $expected, got $actual";
                return $errors; // If type mismatches, stop further checks (except we may still check for null)
            }
        }

        // --- Null (after type check) ---
        if ($value === null) {
            return $errors; // Nothing else to validate for null
        }

        // --- Enum ---
        if (isset($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $allowed = implode(', ', array_map('json_encode', $schema['enum']));
                $errors[] = "value must be one of: $allowed";
            }
        }

        // --- String constraints ---
        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = "length must be at least {$schema['minLength']}";
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = "length must be at most {$schema['maxLength']}";
            }
            if (isset($schema['pattern']) && !preg_match('{' . $schema['pattern'] . '}', $value)) {
                $errors[] = "does not match pattern '{$schema['pattern']}'";
            }
        }

        // --- Numeric constraints ---
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum'])) {
                if (isset($schema['exclusiveMinimum'])) {
                    $exclusive = $schema['exclusiveMinimum'] === true
                        ? $schema['minimum']
                        : (is_numeric($schema['exclusiveMinimum']) ? $schema['exclusiveMinimum'] : null);
                    if ($exclusive !== null && $value <= $exclusive) {
                        $errors[] = "must be strictly greater than $exclusive";
                    } elseif ($exclusive === null && $value < $schema['minimum']) {
                        $errors[] = "must be at least {$schema['minimum']}";
                    }
                } elseif ($value < $schema['minimum']) {
                    $errors[] = "must be at least {$schema['minimum']}";
                }
            }
            if (isset($schema['maximum'])) {
                if (isset($schema['exclusiveMaximum'])) {
                    $exclusive = $schema['exclusiveMaximum'] === true
                        ? $schema['maximum']
                        : (is_numeric($schema['exclusiveMaximum']) ? $schema['exclusiveMaximum'] : null);
                    if ($exclusive !== null && $value >= $exclusive) {
                        $errors[] = "must be strictly less than $exclusive";
                    } elseif ($exclusive === null && $value > $schema['maximum']) {
                        $errors[] = "must be at most {$schema['maximum']}";
                    }
                } elseif ($value > $schema['maximum']) {
                    $errors[] = "must be at most {$schema['maximum']}";
                }
            }
        }

        // --- Array constraints ---
        if (is_array($value) && array_keys($value) === range(0, count($value) - 1)) {
            // Only apply list-specific constraints to sequential arrays
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = "at least {$schema['minItems']} items required";
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "at most {$schema['maxItems']} items allowed";
            }
            // items validation (if schema is an object, apply to all items)
            if (isset($schema['items'])) {
                if (is_array($schema['items'])) {
                    // Tuple validation
                    foreach ($schema['items'] as $index => $itemSchema) {
                        if (array_key_exists($index, $value)) {
                            $itemErrors = self::validateSchema($value[$index], $itemSchema);
                            if (!empty($itemErrors)) {
                                $errors[] = "item $index: " . implode('; ', $itemErrors);
                            }
                        }
                    }
                    // Additional items may be allowed by specification, ignore here
                } else {
                    // Single schema for all items
                    foreach ($value as $index => $item) {
                        $itemErrors = self::validateSchema($item, $schema['items']);
                        if (!empty($itemErrors)) {
                            $errors[] = "item $index: " . implode('; ', $itemErrors);
                        }
                    }
                }
            }
        }

        // --- Object validation ---
        if (is_array($value) && !empty($schema['properties'])) {
            // Check required properties
            if (isset($schema['required'])) {
                foreach ($schema['required'] as $requiredProp) {
                    if (!array_key_exists($requiredProp, $value)) {
                        $errors[] = "missing required field '$requiredProp'";
                    }
                }
            }

            // Validate existing properties
            foreach ($value as $prop => $val) {
                if (isset($schema['properties'][$prop])) {
                    $propErrors = self::validateSchema($val, $schema['properties'][$prop]);
                    if (!empty($propErrors)) {
                        $errors[] = "field '$prop': " . implode('; ', $propErrors);
                    }
                } elseif (isset($schema['additionalProperties']) && $schema['additionalProperties'] === false) {
                    $errors[] = "unknown field '$prop' is not allowed";
                }
                // If additionalProperties is a schema, you could validate against it here – omitted for brevity
            }
        }

        return $errors;
    }

    /**
     * Map PHP value to one of the JSON Schema primitive types.
     */
    private static function checkType($value, string $type): bool
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'integer':
                return is_int($value);
            case 'number':
                return is_int($value) || is_float($value);
            case 'boolean':
                return is_bool($value);
            case 'null':
                return $value === null;
            case 'array':
                return is_array($value); // All PHP arrays satisfy both "array" and "object"
            case 'object':
                // Distinguish between list and map: allow any array, but "object" typically expects associative
                return is_array($value) && (empty($value) || !self::isSequential($value));
            default:
                return false;
        }
    }

    private static function describeType($value): string
    {
        if (is_string($value)) return 'string';
        if (is_int($value)) return 'integer';
        if (is_float($value)) return 'number';
        if (is_bool($value)) return 'boolean';
        if ($value === null) return 'null';
        if (is_array($value)) {
            return self::isSequential($value) ? 'array' : 'object';
        }
        return gettype($value);
    }

    private static function isSequential(array $arr): bool
    {
        if (empty($arr)) return true; // Consider empty as sequential by default
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
