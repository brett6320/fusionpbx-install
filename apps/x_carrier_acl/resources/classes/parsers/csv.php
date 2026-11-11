<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.
*/

/**
 * Parser "csv" (docs/design/carrier_acl.md section 2.3).
 *
 * Options: column (header name or 0-based index, required), delimiter (default ","), header (bool, default true),
 * role (default signaling), role_column + role_map (cell value => signaling|media; rows whose value is not in the
 * map are skipped). Maximum 100 000 rows.
 */
class x_carrier_acl_parser_csv {

	const max_rows = 100000;

	public function option_errors(array $options): array {
		$errors = [];
		foreach (array_keys($options) as $key) {
			if (!in_array($key, ['column', 'delimiter', 'header', 'role', 'role_column', 'role_map'], true)) {
				$errors[] = 'unknown option: '.$key;
			}
		}
		if (!isset($options['column']) || !(is_int($options['column']) || (is_string($options['column']) && $options['column'] !== ''))) {
			$errors[] = 'column is required (header name or 0-based index)';
		}
		if (isset($options['delimiter']) && (!is_string($options['delimiter']) || strlen($options['delimiter']) !== 1)) {
			$errors[] = 'delimiter must be one character';
		}
		if (isset($options['header']) && !is_bool($options['header'])) {
			$errors[] = 'header must be true or false';
		}
		if (isset($options['role']) && !in_array($options['role'], ['signaling', 'media'], true)) {
			$errors[] = 'role must be signaling or media';
		}
		if (isset($options['role_column']) || isset($options['role_map'])) {
			if (!isset($options['role_column']) || !(is_int($options['role_column']) || is_string($options['role_column']))) {
				$errors[] = 'role_column is required with role_map';
			}
			if (!isset($options['role_map']) || !is_array($options['role_map'])) {
				$errors[] = 'role_map must be an object';
			}
			else {
				foreach ($options['role_map'] as $role) {
					if (!in_array($role, ['signaling', 'media'], true)) {
						$errors[] = 'role_map values must be signaling or media';
						break;
					}
				}
			}
		}
		if (is_string($options['column'] ?? null) && !ctype_digit($options['column']) && isset($options['header']) && $options['header'] === false) {
			$errors[] = 'a column name needs header = true';
		}
		return $errors;
	}

	public function parse(string $body, array $options): array {
		$delimiter = $options['delimiter'] ?? ',';
		$header = $options['header'] ?? true;
		$default_role = $options['role'] ?? 'signaling';
		$column = $options['column'] ?? 0;
		$role_column = $options['role_column'] ?? null;
		$role_map = $options['role_map'] ?? null;

		$stream = fopen('php://temp', 'w+');
		fwrite($stream, $body);
		rewind($stream);

		$column_index = null;
		$role_index = null;
		$entries = [];
		$row_number = 0;
		while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
			$row_number++;
			if ($row_number > self::max_rows + 1) {
				fclose($stream);
				throw new RuntimeException('more than '.self::max_rows.' rows');
			}
			if ($row === [null]) {
				continue;
			}
			if ($column_index === null) {
				$column_index = $this->resolve_column($column, $header ? $row : null);
				if ($column_index === null) {
					fclose($stream);
					throw new RuntimeException('column not found: '.$column);
				}
				if ($role_column !== null) {
					$role_index = $this->resolve_column($role_column, $header ? $row : null);
					if ($role_index === null) {
						fclose($stream);
						throw new RuntimeException('role column not found: '.$role_column);
					}
				}
				if ($header) {
					continue;
				}
			}
			$value = trim((string)($row[$column_index] ?? ''));
			if ($value === '') {
				continue;
			}
			$role = $default_role;
			if ($role_index !== null && is_array($role_map)) {
				$key = trim((string)($row[$role_index] ?? ''));
				if (!array_key_exists($key, $role_map)) {
					continue;
				}
				$role = $role_map[$key];
			}
			$entries[] = ['cidr' => $value, 'role' => $role, 'label' => 'row '.$row_number];
		}
		fclose($stream);
		return ['entries' => $entries, 'version' => null];
	}

	/**
	 * Column index from a 0-based index or a header name.
	 */
	private function resolve_column($column, ?array $header_row): ?int {
		if (is_int($column) || (is_string($column) && ctype_digit($column))) {
			return (int)$column;
		}
		if ($header_row === null) {
			return null;
		}
		foreach ($header_row as $index => $name) {
			if (trim((string)$name) === $column) {
				return (int)$index;
			}
		}
		return null;
	}

}

?>
