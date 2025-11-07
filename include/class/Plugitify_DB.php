<?php

class Plugitify_DB {
    protected $wpdb;
    protected $table_name;
    protected $query;
    protected $select = '*';
    protected $wheres = [];
    protected $orderBy = [];
    protected $limit = null;
    protected $offset = null;
    protected $schema = [];
    
    // Plugin prefix
    const PLUGIN_PREFIX = 'plugitify_';
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = self::getFullTableName('plugitify');
    }
    
    /**
     * Get full table name with WordPress prefix and plugin prefix
     */
    public static function getFullTableName($table_name) {
        global $wpdb;
        // Remove plugin prefix if already exists to avoid duplication
        if (strpos($table_name, self::PLUGIN_PREFIX) === 0) {
            $table_name = substr($table_name, strlen(self::PLUGIN_PREFIX));
        }
        return $wpdb->prefix . self::PLUGIN_PREFIX . $table_name;
    }
    
    /**
     * Set table name (like Laravel table() method)
     */
    public static function table($table_name) {
        $instance = new self();
        $instance->table_name = self::getFullTableName($table_name);
        return $instance;
    }
    
    /**
     * Select columns
     */
    public function select($columns = '*') {
        if (is_array($columns)) {
            $this->select = implode(', ', $columns);
        } else {
            $this->select = $columns;
        }
        return $this;
    }
    
    /**
     * Where clause
     */
    public function where($column, $operator = null, $value = null, $boolean = 'AND') {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->wheres[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean
        ];
        return $this;
    }
    
    /**
     * Where with OR
     */
    public function orWhere($column, $operator = null, $value = null) {
        return $this->where($column, $operator, $value, 'OR');
    }
    
    /**
     * Where IN
     */
    public function whereIn($column, $values) {
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => 'AND'
        ];
        return $this;
    }
    
    /**
     * Where NOT IN
     */
    public function whereNotIn($column, $values) {
        $this->wheres[] = [
            'type' => 'not_in',
            'column' => $column,
            'values' => $values,
            'boolean' => 'AND'
        ];
        return $this;
    }
    
    /**
     * Where NULL
     */
    public function whereNull($column) {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => 'AND'
        ];
        return $this;
    }
    
    /**
     * Where NOT NULL
     */
    public function whereNotNull($column) {
        $this->wheres[] = [
            'type' => 'not_null',
            'column' => $column,
            'boolean' => 'AND'
        ];
        return $this;
    }
    
    /**
     * Order by
     */
    public function orderBy($column, $direction = 'ASC') {
        $this->orderBy[] = [
            'column' => $column,
            'direction' => strtoupper($direction)
        ];
        return $this;
    }
    
    /**
     * Limit
     */
    public function limit($limit) {
        $this->limit = $limit;
        return $this;
    }
    
    /**
     * Offset
     */
    public function offset($offset) {
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * Build where clause
     */
    protected function buildWhere() {
        $where_clause = '';
        $where_values = [];
        
        if (!empty($this->wheres)) {
            $conditions = [];
            foreach ($this->wheres as $where) {
                $boolean = isset($conditions) && count($conditions) > 0 ? ' ' . $where['boolean'] . ' ' : '';
                
                if (isset($where['type'])) {
                    switch ($where['type']) {
                        case 'in':
                            $placeholders = implode(',', array_fill(0, count($where['values']), '%s'));
                            $conditions[] = $boolean . esc_sql($where['column']) . ' IN (' . $placeholders . ')';
                            $where_values = array_merge($where_values, $where['values']);
                            break;
                        case 'not_in':
                            $placeholders = implode(',', array_fill(0, count($where['values']), '%s'));
                            $conditions[] = $boolean . esc_sql($where['column']) . ' NOT IN (' . $placeholders . ')';
                            $where_values = array_merge($where_values, $where['values']);
                            break;
                        case 'null':
                            $conditions[] = $boolean . esc_sql($where['column']) . ' IS NULL';
                            break;
                        case 'not_null':
                            $conditions[] = $boolean . esc_sql($where['column']) . ' IS NOT NULL';
                            break;
                    }
                } else {
                    $conditions[] = $boolean . esc_sql($where['column']) . ' ' . esc_sql($where['operator']) . ' %s';
                    $where_values[] = $where['value'];
                }
            }
            $where_clause = ' WHERE ' . implode('', $conditions);
        }
        
        return ['clause' => $where_clause, 'values' => $where_values];
    }
    
    /**
     * Get all results
     */
    public function get() {
        $where_data = $this->buildWhere();
        $table_name_safe = esc_sql($this->table_name);
        // Select is safe - it comes from internal code (select() method), not user input
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Select columns are from internal code
        $query = "SELECT {$this->select} FROM {$table_name_safe}";
        $query .= $where_data['clause'];
        
        if (!empty($this->orderBy)) {
            $order_parts = [];
            foreach ($this->orderBy as $order) {
                $order_parts[] = esc_sql($order['column']) . ' ' . esc_sql($order['direction']);
            }
            $query .= ' ORDER BY ' . implode(', ', $order_parts);
        }
        
        if ($this->limit !== null) {
            $query .= ' LIMIT ' . intval($this->limit);
            if ($this->offset !== null) {
                $query .= ' OFFSET ' . intval($this->offset);
            }
        }
        
        if (!empty($where_data['values'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
            $results = $this->wpdb->get_results($this->wpdb->prepare($query, $where_data['values']));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input in query, table/columns are escaped
            $results = $this->wpdb->get_results($query);
        }
        $this->resetQuery();
        
        // Return empty array if no results or error occurred
        if ($results === false || $results === null) {
            return array();
        }
        
        return $results;
    }
    
    /**
     * Get first result
     */
    public function first() {
        $this->limit(1);
        $results = $this->get();
        return !empty($results) ? $results[0] : null;
    }
    
    /**
     * Find by ID
     */
    public function find($id) {
        return $this->where('id', $id)->first();
    }
    
    /**
     * Count results
     */
    public function count() {
        $where_data = $this->buildWhere();
        $table_name_safe = esc_sql($this->table_name);
        $query = "SELECT COUNT(*) FROM {$table_name_safe}";
        $query .= $where_data['clause'];
        
        if (!empty($where_data['values'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
            $count = $this->wpdb->get_var($this->wpdb->prepare($query, $where_data['values']));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input in query, table is escaped
            $count = $this->wpdb->get_var($query);
        }
        $this->resetQuery();
        return (int) $count;
    }
    
    /**
     * Insert data
     */
    public function insert($data, $format = null) {
        // Auto-generate format if not provided
        if ($format === null) {
            $format = array();
            foreach ($data as $value) {
                if (is_int($value)) {
                    $format[] = '%d';
                } elseif (is_float($value)) {
                    $format[] = '%f';
                } else {
                    $format[] = '%s';
                }
            }
        }
        
        $result = $this->wpdb->insert($this->table_name, $data, $format);
        $this->resetQuery();
        return $result !== false ? $this->wpdb->insert_id : false;
    }
    
    /**
     * Insert multiple records
     */
    public function insertBatch($data_array) {
        if (empty($data_array)) {
            return false;
        }
        
        $values = [];
        $columns = array_keys($data_array[0]);
        $placeholders = [];
        
        foreach ($data_array as $data) {
            $row_values = [];
            foreach ($columns as $column) {
                $row_values[] = $this->wpdb->prepare('%s', $data[$column]);
            }
            $placeholders[] = '(' . implode(',', $row_values) . ')';
        }
        
        $columns_str = implode(',', array_map(function($col) { return "`" . esc_sql($col) . "`"; }, $columns));
        $table_name_safe = esc_sql($this->table_name);
        // Note: Values are already prepared in the loop above using wpdb->prepare('%s', ...)
        $query = "INSERT INTO {$table_name_safe} ({$columns_str}) VALUES " . implode(',', $placeholders);
        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values are already prepared above
        $result = $this->wpdb->query($query);
        $this->resetQuery();
        return $result !== false;
    }
    
    /**
     * Update data
     */
    public function update($data, $format = null, $where_format = null) {
        $where_data = $this->buildWhere();
        
        if (empty($where_data['clause'])) {
            return false; // Prevent updating all rows without where clause
        }
        
        // Auto-generate format if not provided
        if ($format === null) {
            $format = array();
            foreach ($data as $value) {
                if (is_int($value)) {
                    $format[] = '%d';
                } elseif (is_float($value)) {
                    $format[] = '%f';
                } else {
                    $format[] = '%s';
                }
            }
        }
        
        // Auto-generate where_format if not provided
        if ($where_format === null) {
            $where_format = array();
            $where_data = $this->buildUpdateWhere();
            foreach ($where_data as $value) {
                if (is_int($value)) {
                    $where_format[] = '%d';
                } elseif (is_float($value)) {
                    $where_format[] = '%f';
                } else {
                    $where_format[] = '%s';
                }
            }
        }
        
        $result = $this->wpdb->update(
            $this->table_name,
            $data,
            $this->buildUpdateWhere(),
            $format,
            $where_format
        );
        
        $this->resetQuery();
        return $result !== false;
    }
    
    /**
     * Build where for update
     */
    protected function buildUpdateWhere() {
        $where_array = [];
        foreach ($this->wheres as $where) {
            if (!isset($where['type'])) {
                $where_array[$where['column']] = $where['value'];
            }
        }
        return $where_array;
    }
    
    /**
     * Delete records
     */
    public function delete() {
        $where_data = $this->buildWhere();
        
        if (empty($where_data['clause'])) {
            return false; // Prevent deleting all rows without where clause
        }
        
        $table_name_safe = esc_sql($this->table_name);
        $query = "DELETE FROM {$table_name_safe}";
        $query .= $where_data['clause'];
        
        if (!empty($where_data['values'])) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
            $result = $this->wpdb->query($this->wpdb->prepare($query, $where_data['values']));
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input in query, table is escaped
            $result = $this->wpdb->query($query);
        }
        $this->resetQuery();
        return $result !== false;
    }
    
    /**
     * Reset query builder
     */
    protected function resetQuery() {
        $this->select = '*';
        $this->wheres = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
    }
    
    // ==================== Migration Methods ====================
    
    /**
     * Create migration instance
     */
    public static function schema() {
        return new self();
    }
    
    /**
     * Check if table exists
     */
    public static function tableExists($table_name) {
        global $wpdb;
        $full_table_name = self::getFullTableName($table_name);
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name));
        return $table_exists === $full_table_name;
    }
    
    /**
     * Create table
     */
    public function create($table_name, $callback) {
        global $wpdb;
        
        // Check if table already exists
        if (self::tableExists($table_name)) {
            return false; // Table already exists
        }
        
        $instance = new self();
        $instance->wpdb = $wpdb;
        $instance->table_name = self::getFullTableName($table_name);
        $instance->schema = [];
        
        // Execute callback to build schema
        $callback($instance);
        
        // Build CREATE TABLE query
        $charset_collate = $wpdb->get_charset_collate();
        $columns = [];
        
        foreach ($instance->schema as $column) {
            $columns[] = $instance->buildColumnDefinition($column);
        }
        
        $sql = "CREATE TABLE IF NOT EXISTS {$instance->table_name} (\n";
        $sql .= implode(",\n", $columns);
        $sql .= "\n) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dbDelta is WordPress core function for table creation
        $result = dbDelta($sql);
        
        // Verify table was created
        if (self::tableExists($table_name)) {
            return true; // Table was created successfully
        }
        
        return false; // Table creation failed
    }
    
    /**
     * Build column definition
     */
    protected function buildColumnDefinition($column) {
        $definition = "`{$column['name']}` {$column['type']}";
        
        if (isset($column['length']) && $column['length'] !== null) {
            $definition .= "({$column['length']})";
        }
        
        if (isset($column['unsigned']) && $column['unsigned']) {
            $definition .= " UNSIGNED";
        }
        
        if (isset($column['nullable']) && $column['nullable']) {
            $definition .= " NULL";
        } else {
            $definition .= " NOT NULL";
        }
        
        if (isset($column['default'])) {
            if ($column['default'] === 'CURRENT_TIMESTAMP') {
                $definition .= " DEFAULT CURRENT_TIMESTAMP";
            } else {
                $definition .= " DEFAULT '{$column['default']}'";
            }
        }
        
        if (isset($column['auto_increment']) && $column['auto_increment']) {
            $definition .= " AUTO_INCREMENT";
        }
        
        if (isset($column['primary']) && $column['primary']) {
            $definition .= " PRIMARY KEY";
        }
        
        return $definition;
    }
    
    /**
     * Drop table
     */
    public function drop($table_name) {
        global $wpdb;
        $full_table_name = self::getFullTableName($table_name);
        $wpdb_instance = $this->wpdb ? $this->wpdb : $wpdb;
        return $wpdb_instance->query("DROP TABLE IF EXISTS {$full_table_name}");
    }
    
    /**
     * Drop table if exists
     */
    public function dropIfExists($table_name) {
        return $this->drop($table_name);
    }
    
    /**
     * Add column definition to schema
     */
    protected function addColumn($name, $type, $options = []) {
        $this->schema[] = array_merge([
            'name' => $name,
            'type' => $type,
            'nullable' => false,
        ], $options);
        return $this;
    }
    
    /**
     * ID column (auto increment primary key)
     */
    public function id($column = 'id') {
        return $this->addColumn($column, 'BIGINT', [
            'length' => 20,
            'unsigned' => true,
            'auto_increment' => true,
            'primary' => true
        ]);
    }
    
    /**
     * String column
     */
    public function string($column, $length = 255) {
        return $this->addColumn($column, 'VARCHAR', ['length' => $length]);
    }
    
    /**
     * Text column
     */
    public function text($column) {
        return $this->addColumn($column, 'TEXT');
    }
    
    /**
     * Integer column
     */
    public function integer($column, $length = 11) {
        return $this->addColumn($column, 'INT', ['length' => $length]);
    }
    
    /**
     * Big integer column
     */
    public function bigInteger($column) {
        return $this->addColumn($column, 'BIGINT', ['length' => 20]);
    }
    
    /**
     * Boolean column
     */
    public function boolean($column) {
        return $this->addColumn($column, 'TINYINT', ['length' => 1, 'default' => 0]);
    }
    
    /**
     * Date column
     */
    public function date($column) {
        return $this->addColumn($column, 'DATE');
    }
    
    /**
     * DateTime column
     */
    public function dateTime($column) {
        return $this->addColumn($column, 'DATETIME');
    }
    
    /**
     * Timestamp column
     */
    public function timestamp($column, $default = null) {
        $options = [];
        if ($default === 'CURRENT_TIMESTAMP') {
            $options['default'] = 'CURRENT_TIMESTAMP';
        }
        return $this->addColumn($column, 'TIMESTAMP', $options);
    }
    
    /**
     * Decimal column
     */
    public function decimal($column, $precision = 8, $scale = 2) {
        return $this->addColumn($column, 'DECIMAL', ['length' => "{$precision},{$scale}"]);
    }
    
    /**
     * JSON column
     */
    public function json($column) {
        return $this->addColumn($column, 'TEXT');
    }
    
    /**
     * Nullable column modifier
     */
    public function nullable() {
        if (!empty($this->schema)) {
            $this->schema[count($this->schema) - 1]['nullable'] = true;
        }
        return $this;
    }
    
    /**
     * Default value modifier
     */
    public function default($value) {
        if (!empty($this->schema)) {
            $this->schema[count($this->schema) - 1]['default'] = $value;
        }
        return $this;
    }
    
    /**
     * Unsigned modifier
     */
    public function unsigned() {
        if (!empty($this->schema)) {
            $this->schema[count($this->schema) - 1]['unsigned'] = true;
        }
        return $this;
    }
    
    /**
     * Timestamps (created_at, updated_at)
     */
    public function timestamps() {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
        return $this;
    }
    
    /**
     * Add column to existing table
     */
    public function addColumnToTable($table_name, $column_name, $type, $options = []) {
        global $wpdb;
        $full_table_name = self::getFullTableName($table_name);
        
        $instance = new self();
        $instance->schema = [];
        $instance->addColumn($column_name, $type, $options);
        $column_def = $instance->buildColumnDefinition($instance->schema[0]);
        
        $query = "ALTER TABLE {$full_table_name} ADD COLUMN {$column_def}";
        $wpdb_instance = $this->wpdb ? $this->wpdb : $wpdb;
        return $wpdb_instance->query($query);
    }
    
    /**
     * Drop column from table
     */
    public function dropColumn($table_name, $column_name) {
        global $wpdb;
        $full_table_name = self::getFullTableName($table_name);
        $query = "ALTER TABLE {$full_table_name} DROP COLUMN `{$column_name}`";
        $wpdb_instance = $this->wpdb ? $this->wpdb : $wpdb;
        return $wpdb_instance->query($query);
    }
}

// Don't auto-instantiate
// new Plugitify_DB();