<?php
/** @noinspection UnknownColumnInspection */
declare(strict_types = 1);

namespace Blacky0892\LaravelDatatables;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class DataTable
{
    private int     $draw;

    private int     $start; // С какой записи выбирать

    private int     $rowPerPage; // Количество записей на странице

    private array   $columnNameArr; // список всех столбцов

    private Model   $model; // Модель для поиска

    private array   $filters; // установленные фильтры

    private bool    $isFilter = false; // Установлен ли хоть один фильтр

    private int     $totalRecords = 0; // Общее количество записей в таблице

    private ?string $searchValue; // Значение в строке поиска

    private string   $columnName; // Имя столбца для сортировки

    private string   $columnSortOrder;  // Порядок сортировки

    private string   $tableName; // Имя таблицы в базе

    public Builder  $records; // Запрос для получения записей из базы

    /**
     * Инициализация
     * @param Request $request Пришедшие данные из DataTable
     * @param string  $model   Модель для поиска
     */
    public function __construct(Request $request, string $model)
    {

        $this->draw       = (int) $request->get('draw');
        $this->start      = (int) $request->get('start');
        $this->rowPerPage = (int) $request->get('length');

        $orderArr    = $request->get('order');
        $columnIndex = $orderArr[0]['column'] ?? 0;

        $this->columnNameArr   = $request->get('columns');
        $this->columnName      = $this->columnNameArr[$columnIndex]['data'];
        $this->columnSortOrder = $orderArr[0]['dir'] ?? 'asc';
        $this->searchValue     = $request->get('search')['value'];

        $this->model        = new $model();
        $this->totalRecords = $this->getTotalCount();
        $this->tableName    = $this->model->getTable();

        $this->records = $this->model::query();
    }

    /**
     * Общее количество записей в таблице
     * @return int
     */
    public function getTotalCount(): int
    {
        return $this->totalRecords === 0 ? $this->model::count() : $this->totalRecords;
    }

    /**
     * Ручная установка общего числа записей, если применён какой-либо общий фильтр
     * @return void
     */
    public function setTotalCount(){
        $this->totalRecords = $this->records->count();
    }

    /**
     * Установка простых фильтров
     * @param array $filters Список фильтров вида [name, fk, child],
     *                       где fk - указатель, является ли столбец внешним ключом,
     *                       prefix - префикс для поля
     *                       child - промежуточная таблица
     */
    public function simpleFilters(array $filters)
    {
        $this->checkFilters(array_column($filters, 'name'));
        foreach ($filters as $filter) {
            $this->simpleFilter($filter['name'], $filter['fk'], $filter['prefix'] ?? null, $filter['child'] ?? null);
        }
    }

    /**
     * Проверка установлены ли определенные фильтры
     * @param array $filters
     */
    private function checkFilters(array $filters)
    {
        foreach ($filters as $filter) {
            $this->filters[$filter] = $this->columnFilter($filter);
        }
    }

    /**
     * Фильтрация по колонке
     * @param string $needle Имя колонки для фильтрации
     * @return string
     */
    public function columnFilter(string $needle): ?string
    {
        $key = array_search($needle, array_column($this->columnNameArr, 'data'));

        return $this->columnNameArr[$key]['search']['value'];
    }

    /**
     * Установка простого фильтра - поиск по значению
     * @param  string  $filter  Столбец для фильтрации
     * @param  bool  $isFK  Является ли столбец внешним ключом?
     *                       Если да, добавляем к его названию _id
     * @param  string|null  $prefix
     * @param  string|null  $child Промежуточная таблица
     */
    private function simpleFilter(string $filter, bool $isFK = false, string $prefix = null, string $child = null)
    {
        $f = $this->filters[$filter];
        if (!is_null($f) && intval($f) !== 0) {
            $where         = $isFK ? $filter . '_id' : $filter;
            $where = is_null($prefix) ? $where : $prefix . $where;
            if(is_null($child))
            {
                $this->records->where($where, $f);
            }
            else {
                $this->records->whereHas($child, function($q) use ($where, $f){
                    $q->where($where, $f);
                });
            }
            $this->isFilter = true;
        }
    }

    /**
     * Установка фильтра по дате
     * @param string $field Имя поля с датой
     */
    public function dateFilter(string $field = 'date')
    {
        $this->checkFilters([$field]);
        if (!is_null($this->filters[$field]) && $this->filters[$field] !== 'null') {
            $dates     = explode(' — ', $this->filters[$field]);
            $startDate = Carbon::parse($dates[0]);
            $end       = $dates[1] ?? $dates[0];
            $endDate   = Carbon::parse($end)->setTime(23, 59, 59);

            $this->records->whereBetween($field, [$startDate, $endDate]);
            $this->isFilter = true;;
        }
    }

    /**
     * Текстовый поиск в указанных колонках
     * @param array $columns Список колонок для поиска. Может быть именем колонки или массивом из двух элементов:
     *                       key - колонка для поиска, value - значение для поиска
     */
    public function searchFilter(array $columns)
    {
        if ($this->searchValue) {
            // Весь поиск из строки в отдельном Where, т.к тут есть ИЛИ
            $this->records->where(function ($q) use ($columns) {
                foreach ($columns as $column) {
                    if (is_array($column)) {
                        $key   = $column['key'];
                        $value = $column['value'];
                    } else {
                        $key   = $column;
                        $value = $this->searchValue;
                    }
                    /* Для поиска в том случае если разные таблицы
                    имеют одинаковые ключи указываем имя таблицы */
                    $key = $this->tableName . '.' . $key;

                    $q->orWhere($key, 'like', '%' . $value . '%');
                }
            });
            $this->isFilter = true;
        }
    }

    /**
     * Сортировка значений, находящихся в другой таблице
     * @param  string  $table  Таблица со значением
     * @param  string  $field  Поле связывающее с внешней таблицой
     * @param  string  $order  Поле для сортировки
     * @param  bool|null  $isFK Является ли данная таблица внешней
     */
    public function sortWithJoin(string $table, string $field, string $order, bool $isFK = true)
    {
        if($isFK){
            $this->records
                ->leftJoin($table, $table . '.id', '=', $this->tableName . '.' . $field);
        }
        else{
            $this->records
                ->leftJoin($table, $table . '.'. $field, '=', $this->tableName . '.id');
        }
        $this->records
            ->orderBy($table . '.' . $order, $this->columnSortOrder)
            // Хак для избавления от выбора полей с одинаковым именем из разных таблиц
            ->select($this->tableName . '.*', $table . '.' . $order . ' as orderField');
    }

    /**
     *  Сортировка по указанному полю
     */
    public function simpleSort()
    {
        $this->records->orderBy($this->columnName, $this->columnSortOrder);
    }

    /**
     * Получение массива для возврата в DataTable
     * @param array $data Массив с данными
     * @return array
     */
    public function getDTResponse(array $data): array
    {
        return [
            'draw'                 => $this->draw,
            'iTotalRecords'        => $this->totalRecords,
            'iTotalDisplayRecords' => $this->getTotalRecordsWithFilter(),
            'aaData'               => $data,
        ];
    }

    /**
     * Общее количество отфильтрованных записей
     * @return int
     */
    public function getTotalRecordsWithFilter(): int
    {
        return $this->isFilter ? $this->records->count() : $this->totalRecords;
    }

    /**
     * Получение всех записей с фильтрами
     * @return Collection
     */
    public function getRecords(): Collection
    {
        return $this->records->skip($this->start)
            ->take($this->rowPerPage)
            ->get();
    }

    /**
     * Установка значения isFilter для вывода количества
     * отфильтрованных записей
     * @param  bool  $value
     * @return void
     */
    public function setIsFilter(bool $value = true)
    {
        $this->isFilter = $value;
    }

    /**
     * Имя столбца для сортировки
     * @return mixed|string
     */
    public function getColumnName()
    {
        return $this->columnName;
    }

    /**
     * Значение в строке поиска
     * @return mixed|string|null
     */
    public function getSearchValue()
    {
        return $this->searchValue;
    }

    /**
     * Направление сортировки
     * @return mixed|string
     */
    public function getColumnSortOrder()
    {
        return $this->columnSortOrder;
    }

    /**
     * Имя таблицы
     * @return string
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

}