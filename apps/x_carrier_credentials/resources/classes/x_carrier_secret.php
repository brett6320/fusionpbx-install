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
 * Redacting value object. The only thing that carries plaintext between
 * decrypt and push. Casting, dumping, serializing or printing it in an
 * exception trace shows [redacted], never the value.
 */
final class x_carrier_secret implements JsonSerializable {

	/**
	 * @var array object id => plaintext. Kept outside the object so var_export()
	 * and (array) casts of the object show no value.
	 */
	private static $values = [];

	/**
	 * @var int
	 */
	private $id;

	public function __construct(#[\SensitiveParameter] string $value) {
		$this->id = spl_object_id($this);
		self::$values[$this->id] = $value;
	}

	public function __destruct() {
		$this->wipe();
		unset(self::$values[$this->id]);
	}

	/**
	 * Returns the plaintext. Call only at the point of use (push or reveal).
	 */
	public function reveal(): string {
		return self::$values[$this->id] ?? '';
	}

	public function is_empty(): bool {
		return $this->reveal() === '';
	}

	public function equals(x_carrier_secret $other): bool {
		return hash_equals($this->reveal(), $other->reveal());
	}

	/**
	 * Overwrites the plaintext in memory.
	 */
	public function wipe(): void {
		if (isset(self::$values[$this->id]) && self::$values[$this->id] !== '' && function_exists('sodium_memzero')) {
			sodium_memzero(self::$values[$this->id]);
		}
		self::$values[$this->id] = '';
	}

	public function __toString(): string {
		return '[redacted]';
	}

	public function __debugInfo() {
		return [];
	}

	public function __serialize(): array {
		return [];
	}

	public function __unserialize(array $data): void {
		$this->id = spl_object_id($this);
		self::$values[$this->id] = '';
	}

	public function __clone() {
		//a clone gets its own copy so wiping one does not wipe the other
		$value = self::$values[$this->id] ?? '';
		$this->id = spl_object_id($this);
		self::$values[$this->id] = $value;
	}

	public function jsonSerialize(): mixed {
		return '[redacted]';
	}

}

?>
