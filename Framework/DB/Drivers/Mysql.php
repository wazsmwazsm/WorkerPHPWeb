<?php
namespace Framework\DB\Drivers;
use PDO;
use PDOException;
use Closure;
/**
 * Mysql Driver
 *
 * @author MirQin https://github.com/wazsmwazsm
 */
class Mysql implements ConnectorInterface {

    private $_pdo = NULL;
    /**
     * PDOStatement instance
     *
     * @var \PDOStatement
     */
    private $_pdoSt = NULL;
    private $_config = [];

    private $_table = '';
    private $_query_sql = '';

    private $_cols_str = ' * ';
    private $_where_str = '';
    private $_orderby_str = '';
    private $_groupby_str = '';
    private $_having_str = '';
    private $_join_str = '';
    private $_bind_params = [];

    public function __construct($host, $port, $user, $password, $dbname, $charset = 'utf8') {
        $this->_config = [
            'host'     => $host,
            'port'     => $port,
            'user'     => $user,
            'password' => $password,
            'dbname'   => $dbname,
            'charset'  => $charset,
        ];
        $this->_connect();
    }

    private function _connect() {
        $dsn = 'mysql:dbname='.$this->_config['dbname'].
               ';host='.$this->_config['host'].
               ';port='.$this->_config['port'];

        try {
            $this->_pdo = new PDO(
                $dsn,
                $this->_config['user'],
                $this->_config['password'],
                [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES '.$this->_config['charset']]
            );
            // set error mode
            $this->_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // disables emulation of prepared statements
            $this->_pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage());
        }
    }


    public function table($table) {
        $this->_table = self::_backquote($table);
        return $this;
    }

    // memory-resident mode , need manual reset attr
    private function _reset() {
        $this->_table = '';
        $this->_query_sql = '';
        $this->_cols_str = ' * ';
        $this->_where_str = '';
        $this->_orderby_str = '';
        $this->_groupby_str = '';
        $this->_having_str = '';
        $this->_join_str = '';
        $this->_bind_params = [];
    }

    private function _resetBuildStr() {
        $this->_table = '';
        $this->_query_sql = '';
        $this->_cols_str = ' * ';
        $this->_where_str = '';
        $this->_orderby_str = '';
        $this->_groupby_str = '';
        $this->_having_str = '';
        $this->_join_str = '';
    }

    private function _buildQuery() {
        $this->_query_sql = 'SELECT '.$this->_cols_str.' '.' FROM '.$this->_table.
            $this->_join_str.
            $this->_where_str.
            $this->_groupby_str.$this->_having_str.
            $this->_orderby_str;
    }


    private function _bindParams() {
        if(is_array($this->_bind_params)) {
            foreach ($this->_bind_params as $plh => $param) {
                $this->_pdoSt->bindValue($plh, $param);
            }
        }
    }

    private static function _getPlh() {
        return ':'.uniqid();
    }

    private static function _backquote($str) {
        // match pattern
        $alias_pattern = '/([a-zA-Z0-9_\.]+)\s+(AS|as|As)\s+([a-zA-Z0-9_]+)/';
        $alias_replace = '`$1` $2 `$3`';
        $prefix_pattern = '/([a-zA-Z0-9_]+\s*)(\.)(\s*[a-zA-Z0-9_]+)/';
        $prefix_replace = '`$1`$2`$3`';
        $func_pattern = '/[a-zA-Z0-9_]+\([a-zA-Z0-9_\,\s\`\'\"\*]*\)/';
        // alias mode
        if(preg_match($alias_pattern, $str, $alias_match)) {
            // if field is aa.bb mode
            if(preg_match($prefix_pattern, $alias_match[1])) {
                $pre_rst = preg_replace($prefix_pattern, $prefix_replace, $alias_match[1]);
                $alias_replace = $pre_rst.' $2 `$3`';
            }
            return preg_replace($alias_pattern, $alias_replace, $str);
        }
        // prefix mode
        if(preg_match($prefix_pattern, $str)) {
            return preg_replace($prefix_pattern, $prefix_replace, $str);
        }
        // mysql fun mode
        if(preg_match($func_pattern, $str)) {
            return $str;
        }
        // field mode
        return '`'.$str.'`';
    }

    public function select() {
        $cols = func_get_args();

        if( ! func_num_args() || in_array('*', $cols)) {
            $this->_cols_str = ' * ';
        } else {
            $this->_cols_str = '';
            foreach ($cols as $col) {
                $this->_cols_str .= ' '.self::_backquote($col).',';
            }
            $this->_cols_str = rtrim($this->_cols_str, ',');
        }

        return $this;
    }


    public function debugDumpParams() {
        $this->_buildQuery();

        $this->_pdoSt = $this->_pdo->prepare($this->_query_sql);
        $this->_bindParams();

        $this->_reset();
        $this->_pdoSt->execute();

        return $this->_pdoSt->debugDumpParams();
    }

    public function get() {
        $this->_buildQuery();
        // var_dump($this->_query_sql);
        $this->_pdoSt = $this->_pdo->prepare($this->_query_sql);
        $this->_bindParams();

        $this->_reset();
        $this->_pdoSt->execute();
        return $this->_pdoSt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function row() {
        $this->_buildQuery();

        $this->_pdoSt = $this->_pdo->prepare($this->_query_sql);
        $this->_bindParams();

        $this->_reset();
        $this->_pdoSt->execute();
        return $this->_pdoSt->fetch(PDO::FETCH_ASSOC);
    }

    public function list($field) {
        $this->_cols_str = ' `'.$field.'` AS col_list ';
        return array_column($this->get(), 'col_list');
    }

    public function count($field = '*') {

        if(trim($field) != '*') {
            $field = '`'.$field.'`';
        }

        $this->_cols_str = ' COUNT('.$field.') AS count_num ';

        return $this->row()['count_num'];
    }

    public function sum($field) {

        $this->_cols_str = ' SUM(`'.$field.'`) AS sum_num ';

        return $this->row()['sum_num'];
    }

    public function max($field) {

        $this->_cols_str = ' MAX(`'.$field.'`) AS max_num ';

        return $this->row()['max_num'];
    }

    public function min($field) {

        $this->_cols_str = ' MIN(`'.$field.'`) AS min_num ';

        return $this->row()['min_num'];
    }

    public function avg($field) {

        $this->_cols_str = ' AVG(`'.$field.'`) AS avg_num ';

        return $this->row()['avg_num'];
    }

    // 条件构造的统一操作
    private function _condition_constructor($args_num, $params, $operator, &$construct_str) {

        // params dose not conform to specification
        if( ! $args_num || $args_num > 3) {
            throw new PDOException("Error number of parameters");
        }

        switch ($args_num) {
          case 1:
              if( ! is_array($params[0])) {
                  throw new PDOException($params[0].' should be Array');
              }
              foreach ($params[0] as $field => $value) {
                  $plh = self::_getPlh();
                  $construct_str .= ' '.self::_backquote($field).' = '.$plh.' '.$operator;
                  $this->_bind_params[$plh] = $value;
              }
              // 想想有没有更好的处理方案
              $construct_str = rtrim($construct_str, $operator);
              break;
          case 2:
              $plh = self::_getPlh();
              $construct_str .= ' '.self::_backquote($params[0]).' = '.$plh.' ';
              $this->_bind_params[$plh] = $params[1];
              break;
          case 3:
              if( ! in_array($params[1], ['<', '>', '<=', '>=', '=', '!=', '<>'])) {
                  throw new PDOException('Confusing Symbol '.$params[1]);
              }
              $plh = self::_getPlh();
              $construct_str .= ' '.self::_backquote($params[0]).' '.$params[1].' '.$plh.' ';
              $this->_bind_params[$plh] = $params[2];
              break;
        }
    }

    private function subBuilder(Closure $callback) {

        // attribute need to store
        $filters = [
          '_table',
          '_query_sql',
          '_cols_str',
          '_where_str',
          '_orderby_str',
          '_groupby_str',
          '_having_str',
          '_join_str',
        ];

        $stage = [];
        // store attr
        foreach ($filters as $filter) {
            $stage[ltrim($filter, '_')] = $this->$filter;
        }

        /**************** begin sub query build ****************/
            // empty attribute
            $this->_resetBuildStr();
            // call sub query callback
            call_user_func($callback, $this);
            // get sub query build attr
            $sub_attr = [];

            $this->_buildQuery();

            foreach ($filters as $filter) {
                $sub_attr[ltrim($filter, '_')] = $this->$filter;
            }
        /**************** end sub query build ****************/

        // restore attribute
        foreach ($filters as $filter) {
            $this->$filter = $stage[ltrim($filter, '_')];
        }

        return $sub_attr;
    }

    public function where() {

        $operator = 'AND';

        // is the first time call where method ?
        if($this->_where_str == '') {
            $this->_where_str = ' WHERE ';
        } else {
            $this->_where_str .= ' '.$operator.' ';
        }

        $this->_condition_constructor(func_num_args(), func_get_args(), $operator, $this->_where_str);

        return $this;
    }

    public function orWhere() {

        $operator = 'OR';

        // is the first time call where method ?
        if($this->_where_str == '') {
            $this->_where_str = ' WHERE ';
        } else {
            $this->_where_str .= ' '.$operator.' ';
        }

        $this->_condition_constructor(func_num_args(), func_get_args(), $operator, $this->_where_str);

        return $this;
    }

    public function whereIn($field, Array $data, $condition = 'IN', $operator = 'AND') {
        // create placeholder
        foreach ($data as $key => $value) {
            $plh = self::_getPlh();
            $data[$key] = $plh;
            $this->_bind_params[$plh] = $value;
        }
        // is the first time call where method ?
        if($this->_where_str == '') {
            $this->_where_str = ' WHERE '.self::_backquote($field).' '.$condition.' ('.implode(',', $data).')';
        } else {
            $this->_where_str .= ' '.$operator.' '.self::_backquote($field).' '.$condition.' ('.implode(',', $data).')';
        }

        return $this;
    }

    public function orWhereIn($field, Array $data) {
        return $this->whereIn($field, $data, 'IN', 'OR');
    }

    public function whereNotIn($field, Array $data) {
        return $this->whereIn($field, $data, 'NOT IN', 'AND');
    }

    public function orWhereNotIn($field, Array $data) {
        return $this->whereIn($field, $data, 'NOT IN', 'OR');
    }

    public function whereBetween($field, $start, $end, $operator = 'AND') {
        // create placeholder
        $start_plh = self::_getPlh();
        $end_plh = self::_getPlh();
        $this->_bind_params[$start_plh] = $start;
        $this->_bind_params[$end_plh] = $end;

        // is the first time call where method ?
        if($this->_where_str == '') {
            $this->_where_str = ' WHERE '.self::_backquote($field).' BETWEEN '.$start_plh.' AND '.$end_plh;
        } else {
            $this->_where_str .= ' '.$operator.' '.self::_backquote($field).' BETWEEN '.$start_plh.' AND '.$end_plh;
        }

        return $this;
    }

    public function orWhereBetween($field, $start, $end) {
        return $this->whereBetween($field, $start, $end, 'OR');
    }

    public function whereNull($field, $condition = 'NULL', $operator = 'AND') {
        // is the first time call where method ?
        if($this->_where_str == '') {
            $this->_where_str = ' WHERE ';
        } else {
            $this->_where_str .= ' '.$operator.' ';
        }

        $this->_where_str .= self::_backquote($field).' IS '.$condition.' ';

        return $this;
    }

    public function whereNotNull($field) {
        return $this->whereNull($field, 'NOT NULL', 'AND');
    }

    public function orWhereNull($field) {
        return $this->whereNull($field, 'NULL', 'OR');
    }

    public function orWhereNotNull($field) {
        return $this->whereNull($field, 'NOT NULL', 'OR');
    }

    public function whereBrackets(Closure $callback, $operator = 'AND') {
        // first time call where ?
        if($this->_where_str == '') {
            $this->_where_str = ' WHERE ( ';
        } else {
            $this->_where_str .= ' '.$operator.' ( ';
        }
        $sub_attr = $this->subBuilder($callback);

        $this->_where_str .= preg_replace('/WHERE/', '', $sub_attr['where_str'], 1).' ) ';

        return $this;
    }

    public function orWhereBrackets(Closure $callback) {
        return $this->whereBrackets($callback, 'OR');
    }

    public function whereExists(Closure $callback, $condition = 'EXISTS', $operator = 'AND') {
        // first time call where ?
        if($this->_where_str == '') {
            $this->_where_str = ' WHERE '.$condition.' ( ';
        } else {
            $this->_where_str .= ' '.$operator.' '.$condition.' ( ';
        }

        $sub_attr = $this->subBuilder($callback);
        $this->_where_str .= $sub_attr['query_sql'].' ) ';

        return $this;
    }

    public function whereNotExists(Closure $callback) {
        return $this->whereExists($callback, 'NOT EXISTS', 'AND');
    }

    public function orWhereExists(Closure $callback) {
        return $this->whereExists($callback, 'EXISTS', 'OR');
    }
    public function orWhereNotExists(Closure $callback) {
        return $this->whereExists($callback, 'NOT EXISTS', 'OR');
    }

    public function whereInSub($field, Closure $callback, $condition = 'IN', $operator = 'AND') {
        // first time call where ?
        if($this->_where_str == '') {
            $this->_where_str = ' WHERE '.self::_backquote($field).' '.$condition.' ( ';
        } else {
            $this->_where_str .= ' '.$operator.' '.self::_backquote($field).' '.$condition.' ( ';
        }

        $sub_attr = $this->subBuilder($callback);
        $this->_where_str .= $sub_attr['query_sql'].' ) ';

        return $this;
    }

    public function whereNotInSub($field, Closure $callback) {
        return $this->whereInSub($field, $callback, 'NOT IN', 'AND');
    }

    public function orWhereInSub($field, Closure $callback) {
        return $this->whereInSub($field, $callback, 'IN', 'OR');
    }

    public function orWhereNotInSub($field, Closure $callback) {
        return $this->whereInSub($field, $callback, 'NOT IN', 'OR');
    }

    public function fromSub(Closure $callback) {
        $sub_attr = $this->subBuilder($callback);
        $this->_table .= ' ( '.$sub_attr['query_sql'].' ) AS tb_'.uniqid().' ';
        return $this;
    }

    public function groupBy($field) {
        // is the first time call groupBy method ?
        if($this->_groupby_str == '') {
            $this->_groupby_str = ' GROUP BY '.self::_backquote($field);
        } else {
            $this->_groupby_str .= ' , '.self::_backquote($field);
        }

        return $this;
    }

    public function having() {
        $operator = 'AND';

        // is the first time call where method ?
        if($this->_having_str == '') {
            $this->_having_str = ' HAVING ';
        } else {
            $this->_having_str .= ' '.$operator.' ';
        }

        $this->_condition_constructor(func_num_args(), func_get_args(), $operator, $this->_having_str);

        return $this;
    }

    public function orHaving() {
        $operator = 'OR';

        // is the first time call where method ?
        if($this->_having_str == '') {
            $this->_having_str = ' HAVING ';
        } else {
            $this->_having_str .= ' '.$operator.' ';
        }

        $this->_condition_constructor(func_num_args(), func_get_args(), $operator, $this->_having_str);

        return $this;
    }

    public function orderBy($field, $mode = 'ASC') {
        // is the first time call orderBy method ?
        if($this->_orderby_str == '') {
            $this->_orderby_str = ' ORDER BY '.self::_backquote($field).' '.$mode;
        } else {
            $this->_orderby_str .= ' , '.self::_backquote($field).' '.$mode;
        }

        return $this;
    }

    public function join($table, $one, $two, $type = 'INNER') {
        // create join string
        $this->_join_str .= ' '.$type.' JOIN '.self::_backquote($table).
            ' ON '.self::_backquote($one).' = '.self::_backquote($two);
        return $this;
    }

    public function leftJoin($table, $one, $two) {
        return $this->join($table, $one, $two, 'LEFT');
    }

    public function rightJoin($table, $one, $two) {
        return $this->join($table, $one, $two, 'RIGHT');
    }

    public function query($sql) {
        return $this->_pdo->query($sql);
    }

    public function exec($sql) {
        return $this->_pdo->exec($sql);
    }

    public function prepare($sql, Array $driver_options = []) {
        return $this->_pdo->prepare($sql, $driver_options);
    }



    public function insert() {

    }

    public function update() {

    }

    public function delete() {

    }

}
