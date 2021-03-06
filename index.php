<?php

class ParseWriteData
{
    public $logs = [];
    public $nameLogDirectory;
    public $nameLogFile;
    public $connect = false;
    public $options;
    public $data = [];
    private $modernData = [];
    public $nameExceptionListFile;
    public $currentKey = false;
    public $currentOption = false;
    public $table = false;
    public $exceptionList = [];
    private $insert;

    /**
     * Функция конструктор для класс ParseWriteData (PWD).
     *
     * @param array $settings Настройки объекта.
     *
     * Возможные параметры:
     *
     * + boolean 'logging' true если нужно записывать логи используя данный класс, false в ином случае.
     *
     * По умолчанию true.
     *
     * Пример использования
     * ['logging' => true]
     *
     * + string 'nameLogDirectory'|'NLD' наименование директории, в которую буду записываться файлы с логами.
     *
     * По умолчанию наименование формируется так: 'logs/[год][месяц][день]_[часы][минуты]/'.
     *
     * Пример 'logs/210426_0953/'.
     *
     * Примеры использования данного параметра:
     *
     * ['logging' => true, 'nameLogDirectory' => 'logs']
     *
     * ['logging' => true, 'NLD' => 'logs']
     *
     * + string 'nameLogFile'|'NLF' наименование файла, в который будут записываться логи.
     *
     * По умолчанию наименование формируется так: '[день][месяц][год]_[часы][минуты]__[хэш]'.
     *
     * Пример '260421_1003__2579438632.json'
     *
     * Примеры использования данного параметра
     *
     * ['logging' => true, 'nameLogFile' => 'logs.json']
     *
     * ['logging' => true, 'nameLogFile' => 'logs']
     *
     * ['logging' => true, 'NLF' => 'logs']
     *
     * ['logging' => true, 'NLF' => 'logs.json']
     *
     * + string|array 'postgres' массив со значениями для формирования строки для подключения к базу данных или сама строка
     *
     * Параметры массива:
     *
     * string 'host' хост для подключения к базе данных
     *
     * string|numeric 'port' порт
     *
     * string 'dbname' наименование базы данных для подключения
     *
     * string 'user' имя учетной записи
     *
     * string 'password' пароль от учетной записи
     *
     * + array|string 'options' путь к файлу опций для парсинга или сами опции
     *
     * Пример:
     *
     * [["links"=>"http://url.dot", 'login'=>['username'=>'admin', 'password'=>'12345678'],
     * "tables" => ["table_1", "table_2"],
     * "controls" => ["__default" => ["main" => "all", "second" => ["__all" => true, "__prefix" => "%parent%_"]]]]]
     *
     * + boolean updateOptions требуется ли обновлять опции и перезаписывать файл опций если он есть.
     * */

    public function __construct($settings = [])
    {
        $settings = $this->modernArray(['logging', 'nameLogDirectory', 'NLD', 'nameLogFile', 'NLF',
            'postgres', 'options', 'exceptionHandler', 'updateOptions'], ['logging' => true, 'options' => 'option.json'], $settings);

        if ($settings['logging'] == true) {
            $this->logs['time_start'] = date('d.m.y H:i:s');

            if (is_string($settings['nameLogDirectory']) || is_numeric($settings['nameLogDirectory']) ||
                is_string($settings['NLD']) || is_numeric($settings['NLD'])) {
                $this->nameLogDirectory = $settings['nameLogDirectory'] !== false ? $settings['nameLogDirectory'] : $settings['NLD'];
            } else {
                $this->nameLogDirectory = 'logs/' . date('ymd_Hi') . '/';
            }

            mkdir($this->nameLogDirectory);

            if (is_string($settings['nameLogFile']) || is_numeric($settings['nameLogFile']) ||
                is_string($settings['NLF']) || is_numeric($settings['NLF'])) {
                $this->nameLogFile = $settings['nameLogFile'] !== false ? $settings['nameLogFile'] : $settings['NLF'];
                if (strpos($this->nameLogFile, '.json', -5) === false) {
                    $this->nameLogFile .= '.json';
                }
            } else {
                $this->nameLogFile = date('dmy_Hi') . '__' . crc32(microtime(true)) . '.json';
            }

            file_put_contents($this->nameLogDirectory . $this->nameLogFile, '');
        } else {
            $this->logs = false;
        }

        if ($settings['postgres'] !== false) {
            if (is_array($settings['postgres'])) {
                $settings['postgres']['host'] = empty($settings['postgres']['host']) ? 'localhost' : $settings['postgres']['host'];

                $settings['postgres']['port'] = empty($settings['postgres']['port']) ? 5432 : $settings['postgres']['port'];

                $settings['postgres']['dbname'] = empty($settings['postgres']['dbname']) ? 'postgres' : $settings['postgres']['dbname'];

                $settings['postgres']['user'] = empty($settings['postgres']['user']) ? null : $settings['postgres']['user'];

                $settings['postgres']['password'] = empty($settings['postgres']['password']) ? null : $settings['postgres']['password'];

                $this->connect = pg_connect('host=' . $settings['postgres']['host'] . ' port=' . $settings['postgres']['port'] . ' dbname=' . $settings['postgres']['dbname'] . ' user=' . $settings['postgres']['user'] . ' password=' . $settings['postgres']['password']);
            } else {
                $this->connect = pg_connect($settings['postgres']);
            }
        } else {
            $this->connect = false;
        }

        if (is_array($settings['options'])) {
            $this->options = $settings['options'];
        } elseif (is_string($settings['options'])) {
            $this->options = json_decode(file_get_contents($settings['options']), true);
        }

        $this->nameExceptionListFile = date('dmy_Hi') . '__warning__' . crc32(microtime(true)) . '.json';
    }

    //

    public function nextTable()
    {
        $tables = array_values($this->currentOption['tables']);

        if (count($tables) < 1) {
            $this->table = false;
            return false;
        }

        if ($this->table !== false) {
            $this->table = $tables[array_search($this->table) + 1];
        } else {
            $this->table = $tables[0];
        }

        return $this->table;
    }

    public function currentOption()
    {
        if ($this->currentKey === false)
            if ($this->nextOrPrev() === false)
                return false;
        return [$this->currentKey => $this->currentOption];

    }

    public function next()
    {
        return $this->nextOrPrev();
    }

    public function prev()
    {
        return $this->nextOrPrev(false);
    }

    private function nextOrPrev($next = true)
    {
        if (empty($this->options)) {
            return false;
        }
        $keys = array_keys($this->options);
        if ($next) {
            $num = $this->currentKey !== false ? array_search($this->currentKey, $keys) - 1 : 0;
        } else {
            $num = $this->currentKey !== false ? array_search($this->currentKey, $keys) + 1 : 0;
        }

        if ($num < 0 || $num > count($keys))
            $num = false;

        $this->currentKey = $num !== false ? $keys[$num] : false;

        $this->currentOption = $this->currentKey !== false ? $this->options[$this->currentKey] : false;

        if (is_array($this->currentOption['tables']) && !empty($this->currentOption['tables'])) {
            $this->table = array_values($this->currentOption['tables'])[0];
        }

        return $this->currentOption;
    }

    public function parseData()
    {
        if ($this->currentOption === false)
            if ($this->nextOrPrev() === false)
                return false;

        if ($this->table === false)
            if ($this->nextTable() === false)
                return false;

        $this->modernData = $this->removeNesting($this->data, $this->currentOption);

        return $this->modernData;
    }

    public function clearInsertExcHandler()
    {
        $this->insert = false;
    }

    //
    ////

    public function is_dict($arr)
    {
        return !is_numeric(implode('', array_keys($arr)));
    }

    public function modernArray($keys, $defaultValues, $array)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key ,$array)) {
                $array[$key] = in_array($key, array_keys($defaultValues)) ? $defaultValues[$key] : false;
            }
        }

        return $array;
    }

    public function updateLoader($newLoader, $oldLoader, $info = '')
    {
        if ($newLoader != $oldLoader) {
            popen('clear', 'w');
            echo $info . PHP_EOL;
            echo $newLoader;
            return $newLoader;
        } else {
            return $oldLoader;
        }
    }

    public function loader($num, $count, $text = '', $afterText = '')
    {
        $load = $text . ': [';

        if ($num >= $count || $count == 0) {
            return $load . '#########################] ' . $afterText . PHP_EOL;
        }

        $step = 25 / $count;

        for ($i = 0; $i < 25; $i++) {
            if ($i <= ($step * $num)) {
                $load .= '#';
            } else {
                $load .= '.';
            }
        }

        $load .= '] ';
        return $load . $afterText . PHP_EOL;
    }

    public function writeLogs($pathLogsFile, $logs)
    {
        file_put_contents($pathLogsFile, json_encode($logs, JSON_UNESCAPED_UNICODE));
    }

    ////

    public function removeNesting($data, $option, $param = false)
    {
        if (is_object($data))
            $data = (array)$data;

        if (empty($option) || empty($data))
            return $data;

        $option = $this->modernArray(['prefix', 'main', 'second', 'children', 'param'], ['prefix' => ''], $option);

        $res = [];

        $data = array_change_key_case($data);

        if (is_array($option['main'])) {
            foreach ($option['main'] as $item) {
                $res[$option['prefix'] . $item] = $data[$item];
            }
        } elseif ($option['main'] == "__all") {
            foreach ($data as $id => $value) {
                if (!is_array($value) && !is_object($value))
                    $res[strtolower($option['prefix'] . $id)] = $value;
            }
        } elseif (!empty($data[strtolower($option['main'])])) {
            $res[strtolower($option['main'])] = $data[strtolower($option['main'])];
        }

        $option['second']['__all'] = empty($option['second']['__all']) ? false : $option['second']['__all'];

        if ($option['second']['__all'] === true) {
            foreach ($data as $id => $value) {
                if (is_object($value) || is_array($value)) {
                    $res = array_merge(
                        $this->removeNesting((array)$value, [
                            'main' => '__all',
                            'prefix' => str_replace('%parent%', $id, $option['second']['__prefix'])
                        ])
                        , $res);
                }
            }
        } elseif (is_array($option['second'])) {
            foreach ($option['second'] as $id => $item) {
                if (strpos($id, '__') !== 0 && !empty($data[$id])) {
                    $item = $this->modernArray(['prefix', 'array', 'param', 'parentOption', 'name', 'joinMainArray'], ['prefix' => ''], $item);

                    $item['prefix'] = str_replace('%parent%', $id, $item['prefix']);
                    if (is_array($data[$id]) || is_object($data[$id])) {
                        if ($item['array'] === true) {
                            $join = false;
                            if (!empty($res['__join__'])) {
                                $join = $res['__join__'];
                                unset($res['__join__']);
                            }

                            if ($this->is_dict($res) && !empty($res)) {
                                $res = [$res];
                            }

                            if ($join !== false) {
                                $res['__join__'] = $join;
                            }

                            foreach ($data[$id] as $datum) {

                                if (is_array($item['param'])) {
                                    if ($item['parentOption'] === true) {
                                        $temp = $this->removeNesting(
                                            (array)$datum, $option);
                                    } else {
                                        $temp = $this->removeNesting(
                                            (array)$datum,
                                            $item);
                                    }
                                } else {
                                    if ($item['parentOption'] === true) {
                                        $temp = $this->removeNesting((array)$datum, $option);
                                    } else {
                                        $temp = $this->removeNesting((array)$datum, $item);
                                    }
                                }

                                if (is_array($temp)) {
                                    if (!empty($temp['__join__'])) {
                                        if (empty($res['__join__'])) {
                                            $res['__join__'] = [];
                                        }
                                        $res['__join__'] = array_merge($res['__join__'], $temp['__join__']);
                                        unset($temp['__join__']);
                                    }

                                    if (is_string($item['name'])) {

                                        if ($this->is_dict($temp)) {
                                            $res[$item['name']][] = $temp;
                                        } else {
                                            if (empty($res[$item['name']]))
                                                $res[$item['name']] = [];
                                            $res[$item['name']] = array_merge($temp, $res[$item['name']]);
                                        }

                                    } elseif ($item['joinMainArray'] === true) {
                                        if ($this->is_dict($temp)) {
                                            $res['__join__'][] = $temp;
                                        } else {
                                            $res['__join__'] = array_merge($res['__join__'], $temp);
                                        }
                                    } else {
                                        if ($this->is_dict($temp)) {
                                            $res[] = $temp;
                                        } else {
                                            $res = array_merge($res, $temp);
                                        }
                                    }
                                }
                            }
                        } else {
                            if ($item['parentOption'] === true) {
                                $temp = $this->removeNesting((array)$data[$id], $option);
                            } else {
                                $temp = $this->removeNesting((array)$data[$id], $item);
                            }

                            if (!empty($temp['__join__'])) {
                                if (empty($res['__join__'])) {
                                    $res['__join__'] = [];
                                }
                                $res['__join__'] = array_merge($res['__join__'], $temp['__join__']);
                                unset($temp['__join__']);
                            }
                            if ($item['name'] !== false) {
                                $res[$item['name']] = array_merge($res[$item['name']], $temp);
                            } elseif ($item['joinMainArray'] === true) {
                                $res['__join__'][] = $temp;
                            } else {
                                $res = array_merge($res, $temp);
                            }
                        }
                    }
                }
            }
        }

        if ($option['param'] !== false && is_array($option['param'])) {
            if ($this->is_dict($res)) {
                $res = array_merge($res, $option['param']);
            } else {
                foreach ($res as $id => $item) {
                    $res[$id] = array_merge($item, $option['param']);
                }
            }
        }

        return $res;
    }

    public function removeExcessBeta($data, $option, $param = false)
    {
        if (is_object($data))
            $data = (array)$data;

        if (empty($option) || empty($data))
            return $data;

        $option = $this->modernArray(['prefix', 'main', 'second', 'children', 'param'], ['prefix' => ''], $option);

        $res = $main = $second = [];

        $data = array_change_key_case($data);

        if (is_array($option['main'])) {
            foreach ($option['main'] as $item) {
                $main[$option['prefix'] . $item] = $data[$item];
            }
        } elseif ($option['main'] == "__all") {
            foreach ($data as $id => $value) {
                if (!is_array($value) && !is_object($value))
                    $main[strtolower($option['prefix'] . $id)] = $value;
            }
        } elseif (!empty($data[strtolower($option['main'])])) {
            $main[strtolower($option['main'])] = $data[strtolower($option['main'])];
        }

        $option['second']['__all'] = empty($option['second']['__all']) ? false : $option['second']['__all'];

        if ($option['second']['__all'] === true) {
            foreach ($data as $id => $value) {
                if (is_object($value) || is_array($value)) {
                    $second = array_merge(
                        $this->removeExcessBeta((array)$value, [
                            'main' => '__all',
                            'prefix' => str_replace('%parent%', $id, $option['second']['__prefix'])
                        ])
                        , $second);
                }
            }
        } elseif (is_array($option['second'])) {
            foreach ($option['second'] as $id => $item) {
                if (strpos($id, '__') !== 0 && !empty($data[$id])) {
                    $item = $this->modernArray(['prefix', 'array', 'param', 'parentOption', 'name', 'joinMainArray'], ['prefix' => ''], $item);

                    $item['prefix'] = str_replace('%parent%', $id, $item['prefix']);
                    if (is_array($data[$id]) || is_object($data[$id])) {
                        if ($item['array'] === true) {
                            if(empty($second['__array__'])){
                                $second['__array__'] = [];
                            }
                            $numArr = count($second['__array__']);
                            $second['__array__'][$numArr] = [];
                            foreach ($data[$id] as $datum) {
                                if (is_array($item['param'])) {
                                    if ($item['parentOption'] === true) {
                                        $temp = $this->removeExcessBeta(
                                            (array)$datum, $option);
                                    } else {
                                        $temp = $this->removeExcessBeta(
                                            (array)$datum,
                                            $item);
                                    }
                                } else {
                                    if ($item['parentOption'] === true) {
                                        $temp = $this->removeExcessBeta((array)$datum, $option);
                                    } else {
                                        $temp = $this->removeExcessBeta((array)$datum, $item);
                                    }
                                }

                                if (is_array($temp)) {
                                    if (!empty($temp['__join__'])) {
                                        if (empty($res['__join__'])) {
                                            $res['__join__'] = [];
                                        }
                                        $res['__join__'] = array_merge($res['__join__'], $temp['__join__']);
                                        unset($temp['__join__']);
                                    }

                                    if ($item['joinMainArray'] === true) {
                                        if ($this->is_dict($temp)) {
                                            $res['__join__'][] = $temp;
                                        } else {
                                            $res['__join__'] = array_merge($res['__join__'], $temp);
                                        }
                                    } else {
                                        if ($this->is_dict($temp)) {
                                            $second['__array__'][$numArr][] = $temp;
                                        } else {
                                            $second['__array__'][$numArr] = array_merge($second['__array__'][$numArr], $temp);
                                        }
                                    }
                                }
                            }
                        } else {
                            if ($item['parentOption'] === true) {
                                $temp = $this->removeExcessBeta((array)$data[$id], $option);
                            } else {
                                $temp = $this->removeExcessBeta((array)$data[$id], $item);
                            }

                            if (!empty($temp['__join__'])) {
                                if (empty($res['__join__'])) {
                                    $res['__join__'] = [];
                                }
                                $res['__join__'] = array_merge($res['__join__'], $temp['__join__']);
                                unset($temp['__join__']);
                            }
                            if ($item['joinMainArray'] === true) {
                                $second['__join__'] = array_merge($second['__join__'], $temp);
                            } else {
                                $second = array_merge($second, $temp);
                            }
                        }
                    }
                }
            }
        }

        $res = array_merge($main, $second);

        if ($option['param'] !== false && is_array($option['param'])) {
            if ($this->is_dict($res)) {
                $res = array_merge($res, $option['param']);
            } else {
                foreach ($res as $id => $item) {
                    $res[$id] = array_merge($item, $option['param']);
                }
            }
        }

        if(!empty($res[0]) && !empty($res[1])){
            var_dump($res);
            die();
        }

        return $res;
    }

    public function removeNestingBeta($data){
        $res = [];
        if($this->is_dict($data)){
            if (empty($data['__array__'])){
                return $data;
            }

            $main = $data;
            unset($main['__array__']);

            foreach ($data['__array__'] as $array){
                foreach ($array as $item){
                    $item = array_merge($main, $item);
                    $temp = $this->removeNestingBeta($item);
                    if ($this->is_dict($temp)){
                        $res[] = $temp;
                    } else{
                        $res = array_merge($res, $temp);
                    }
                }
            }
        } else{
            foreach ($data as $datum){
                $res = array_merge($res, $this->removeNestingBeta($datum));
            }
        }

        return $res;
    }

    public function getData($url, $isJson = true, $login = false)
    {
        if (!$login) {
            for ($i = 0; $i < 4; $i++) {
                $data = file_get_contents($url);
                if ($isJson) {
                    $data = json_decode($data, true);
                    if (is_array($data)) {
                        $this->data = $data;
                        return $data;
                    }
                }
                $this->data = $data;
                return $data;
            }
            throw new Exception('Не могу получить данные от сайта: ' . $url . PHP_EOL);
        } else {
            if (!empty($login['username']) && !empty($login['password'])) {
                $ch = curl_init();
                if (strtolower((substr($url, 0, 5)) == 'https')) { // если соединяемся с https
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                }
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (Windows; U; Windows NT 5.0; En; rv:1.8.0.2) Gecko/20070306 Firefox/1.0.0.4");
                curl_setopt($ch, CURLOPT_USERPWD, $login['username'] . ':' . $login['password']);
                $result = curl_exec($ch);

                curl_close($ch);

                if ($isJson) {
                    $result = json_decode($result, true);
                    if (is_array($result)) {
                        $this->data = $result;
                        return $result;
                    }
                }
                $this->data = $result;
                return $result;
            }
            if(!empty($login['auth'])){
                $ch = curl_init($url);

                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $login['auth']));
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $result = curl_exec($ch);
                curl_close($ch);

                if ($isJson) {
                    $result = json_decode($result, true);
                    if (is_array($result)) {
                        $this->data = $result;
                        return $result;
                    }
                }

                $this->data = $result;
                return $result;
            }
        }
    }

    public function formatData($data, $options, $columns)
    {
        $res = [];
        $col = [];

        if (empty($data) || empty($columns)) {
            return false;
        }

        $options = $this->modernArray(['__nums', '__notnull', 'dquotes'], ['__nums' => [], '__notnull' => []], $options);

        foreach ($columns as $id => $name) {
            if ($name === false) {
                continue;
            }

            $col[] = is_numeric($id) ? $name : $id;

            if (in_array($name, array_keys($data))) {
                if (in_array($name, $options['__nums']) || (!is_numeric($id) && in_array($id, $options['__nums']))) {
                    if (is_string($data[$name])) {
                        $data[$name] = str_replace(' ', '', $data[$name]);
                        $data[$name] = str_replace(',', '.', $data[$name]);
                    }

                    $res[] = is_numeric($data[$name]) ? $data[$name] : 'null';
                } else {
                    $res[] = "'" . trim(str_replace("'", '"', $data[$name])) . "'";
                    if($options['dquotes'] === true){
                        $res[count($res) - 1] = str_replace("'", "\"", $res[count($res) - 1]);
                        if($res[count($res) - 1] === "\"\""){
                            $res[count($res) - 1] = "";
                        }
                    }
                    if ($res[count($res) - 1] === "''") {
                        $res[count($res) - 1] = 'null';
                    }
                }
            } else {
                $res[] = 'null';
            }

            if ((in_array($name, $options['__notnull']) || (!is_numeric($id) && in_array($id, $options['__notnull'])))
                && $res[count($res) - 1] === 'null') {
                return false;
            }
        }

        return ['columns' => $col, 'values' => $res];
    }

    public function createInsert($data, $col, $table)
    {
        if (empty($data) || empty($col) || empty($table)) {
            return false;
        }

        $insert = 'INSERT INTO ' . $table . '(' . implode(', ', $col) . ') VALUES ';

        if (!$this->is_dict($data)) {
            $values = [];
            foreach ($data as $item) {
                $values[] = '(' . implode(', ', $item) . ')';
            }

            $insert .= implode(', ', $values);
        } else {
            $insert .= '(' . implode(', ', $data) . ')';
        }

        return $insert;
    }

    private function filter($array, $column, &$values, $have = true)
    {
        foreach ($values as $name => $value) {
            if (!is_array($value)) {
                $value = [$value];
            }

            $fieldArrays = array_column($array, array_search($name, $column));

            $res = [];
            foreach ($fieldArrays as $index => $item) {
                $value = $values[$name];
                if (strpos($item, "'") === 0) {
                    $item = substr($item, 1, strlen($item) - 2);
                }
                if ($have) {
                    if (in_array($item, $value)) {
                        $res[] = $array[$index];
                    }
                } else {
                    if (!empty($value)){
                        if (!in_array($item, $value)) {
                            $res[] = $array[$index];
                            $values[$name][] = $item;
                        }
                    }
                    else{
                        $res[] = $array[$index];
                        $values[$name][] = $item;
                    }
                }

            }

            $array = $res;
        }

        return $array;
    }

    public function filterData($array, $column, $have = false, &$haveNot = false)
    {

        if ($have === false && $haveNot === false)
            return false;

        if ($have !== false && is_array($have)) {
            $array = $this->filter($array, $column, $have);
        }

        if ($haveNot !== false && is_array($haveNot)) {
            $array = $this->filter($array, $column, $haveNot, false);
        }

        return count($array) > 0 ? $array : false;
    }

    public function paramsGetRequest($param, $isArr = false)
    {
        //переделать
        function getParam($name, $values, $isArr = false)
        {
            $res = [];
            if (is_array($values)) {
                foreach ($values as $value) {
                    $res[] = $isArr ? [$name => $value] : ($name . '=' . $value);
                }
            } else {
                $posReturn = strrpos($values, '%return');
                if ($posReturn !== false) {
                    $value = substr($values, 0, $posReturn) .
                        eval(substr($values, $posReturn + 1, strrpos($values, '%', $posReturn + 1) - $posReturn - 1)) .
                        substr($values, strrpos($values, '%', $posReturn + 1) + 1);
                    return $isArr ? array([$name => $value]) : [$name . '=' . $value];
                }
                return $isArr ? array([$name => $values]) : [$name . '=' . $values];
            }
            return $res;
        }

        $getParams = [];

        foreach ($param as $name => $value) {
            if ($getParams == []) {
                $getParams[0] = getParam($name, $value, $isArr);
            } else {
                $getParams[1] = getParam($name, $value, $isArr);
                foreach ($getParams[0] as $item) {
                    foreach ($getParams[1] as $item1) {
                        $getParams[2][] = $isArr ? array_merge($item, $item1) : $item . '&' . $item1;
                    }
                }

                array_shift($getParams);
                array_shift($getParams);
            }
        }

        return $getParams[0];

    }

    public function paramsGetRequestBeta($param, $isArr = false)
    {
        //переделать
        function getParam($name, $values, $isArr = false)
        {
            $res = [];
            if (is_array($values)) {
                foreach ($values as $value) {
                    $res[] = $isArr ? [$name => $value] : ($name . '=' . $value);
                }
            } else {
                $posReturn = strrpos($values, '%return');
                if ($posReturn !== false) {
                    $value = substr($values, 0, $posReturn) .
                        eval(substr($values, $posReturn + 1, strrpos($values, '%', $posReturn + 1) - $posReturn - 1)) .
                        substr($values, strrpos($values, '%', $posReturn + 1) + 1);
                    return $isArr ? array([$name => $value]) : [$name . '=' . $value];
                }
                return $isArr ? array([$name => $values]) : [$name . '=' . $values];
            }
            return $res;
        }

        $getParams = [];

        foreach ($param as $name => $value) {
            if ($getParams == []) {
                if($name == "__main"){
                    $getParams[0] = $value;
                } else{
                    $getParams[0] = getParam($name, $value, $isArr);
                }

            } else {
                $getParams[1] = getParam($name, $value, $isArr);
                foreach ($getParams[0] as $item) {
                    foreach ($getParams[1] as $item1) {
                        $getParams[2][] = $isArr ? array_merge($item, $item1) : $item . '&' . $item1;
                    }
                }

                array_shift($getParams);
                array_shift($getParams);
            }
        }

        return $getParams[0];

    }

    public function mergeColumns($optionColumns, $columns)
    {
        if (empty($optionColumns) || empty($columns))
            return false;

        foreach ($optionColumns as $name => $column) {
            if (in_array($name, $columns) !== false) {
                array_splice($columns, array_search($name, $columns), 1);
            }
        }

        return array_merge($columns, $optionColumns);
    }

    public function mergeArray($array)
    {
        if (empty($array['__join__'])) {
            return $array;
        }

        foreach ($array['__join__'] as $join) {
            foreach ($array as $i => $item) {
                $array[$i] = array_merge($item, $join);
            }
        }

        unset($array['__join__']);

        return $array;
    }

    public function getColumns($connect, $tableName)
    {
        return array_column(pg_fetch_all(pg_query($connect, "select column_name,data_type from information_schema.columns where table_name = '" . $tableName . "'")), 'column_name');
    }

    public function exceptionHandler($code, $msg, $file, $line)
    {
        $this->exceptionList[] = [$msg . ' in ' . $file . ' on line ' . $line . PHP_EOL, date('h:i:s'), $this->insert !== false ? $this->insert : null];
        echo $msg . ' in ' . $file . ' on line ' . $line . PHP_EOL;
    }

    public function checkDataDB($colNames, $tableName, $connect = false)
    {
        if (empty($colNames) || ($connect === false && $this->connect === false)) {
            return false;
        }

        if ($connect === false)
            $connect = $this->connect;

        $values = [];

        foreach ($colNames as $colName) {
            $res = pg_fetch_all(pg_query($connect, "SELECT " . $colName . " FROM " . $tableName . " group by " . $colName));
            $values[$colName] = array_column(($res === false?[]:$res), $colName);
        }

        return $values;
    }
}
