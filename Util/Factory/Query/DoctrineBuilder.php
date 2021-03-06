<?php

namespace Lugaidster\DatatableBundle\Util\Factory\Query;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\ORM\Query,
    Doctrine\ORM\Query\Expr\Join;

class DoctrineBuilder implements QueryInterface
{
    /** @var \Symfony\Component\DependencyInjection\ContainerInterface */
    protected $container;

    /** @var \Doctrine\ORM\EntityManager */
    protected $em;

    /** @var \Symfony\Component\HttpFoundation\Request */
    protected $request;

    /** @var \Doctrine\ORM\QueryBuilder */
    protected $queryBuilder;

    /** @var string */
    protected $entity_name;

    /** @var string */
    protected $entity_alias;

    /** @var array */
    protected $fields;

    /** @var string */
    protected $order_field = NULL;

    /** @var string */
    protected $order_type = "asc";

    /** @var string */
    protected $where = NULL;

    /** @var array */
    protected $joins = array();

    /** @var boolean */
    protected $has_action = true;

    /** @var array */
    protected $fixed_data = NULL;

    /** @var closure */
    protected $renderer = NULL;

    /** @var boolean */
    protected $search = FALSE;

    /** @var boolean */
    protected $hasMultiple = FALSE;

    /**
     * class constructor
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container    = $container;
        $this->em           = $this->container->get('doctrine.orm.entity_manager');
        $this->request      = $this->container->get('request');
        $this->queryBuilder = $this->em->createQueryBuilder();
    }

    /**
     * get the search dql
     *
     * @return string
     */
    protected function _addSearch(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
        $request       = $this->request;
        $search_fields = array_values($this->fields);
        if ($this->search == \Lugaidster\DatatableBundle\Util\Datatable::PER_FIELD_SEARCH) {
            $aggregateFields = [];

            foreach ($search_fields as $i => $search_field) {
                if(preg_match('/(min|max|count|sum)[ ]*\\(/i', trim($search_field))) {
                    $aggregateFields[] = $search_field;
                    continue;
                }

                $search_param = $request->get("sSearch_{$i}");

                if ($request->get("sSearch_{$i}") !== false && !empty($search_param)) {
                    $queryBuilder->andWhere(" $search_field like '%{$request->get("sSearch_{$i}")}%' ");
                }
            }

            if(count($aggregateFields) > 0) {
                // TODO: Add aggregate functionality
            }
        } elseif ($this->search == \Lugaidster\DatatableBundle\Util\Datatable::GLOBAL_SEARCH) {

            $orExpr = $queryBuilder->expr()->orX();
            $aggregateFields = [];
            foreach ($search_fields as $i => $search_field) {
                if(preg_match('/(min|max|count|sum)[ ]*\\(/i', trim($search_field))) {
                    $aggregateFields[] = $search_field;
                    continue;
                }

                $search_field = explode(' as ', $search_field);
                $search_field = current($search_field);

                $isSearchable = $request->get("bSearchable_$i") == 'true';
                if(!$isSearchable) continue;

                $search_param = $request->get("sSearch_{$i}");
                $global_search = $request->get("sSearch");

                if (!empty($search_param)) {
                    $queryBuilder->andWhere(" $search_field like '%{$search_param}%' ");
                } elseif (!empty($global_search)) {
                    $orExpr->add(" $search_field like '%{$global_search}%' ");
                }
            }

            if(count($aggregateFields) > 0) {
                // TODO: Add aggregate functionality
            }

            if($orExpr->count() > 0) {
                $queryBuilder->andWhere($orExpr);
            }
        }
    }

    /**
     * add join
     *
     * @example:
     *      ->setJoin(
     *              'r.event',
     *              'e',
     *              \Doctrine\ORM\Query\Expr\Join::INNER_JOIN,
     *              'e.name like %test%')
     *
     * @param string $join_field
     * @param string $alias
     * @param string $type
     * @param string $cond
     *
     * @return Datatable
     */
    public function addJoin($join_field, $alias, $type = Join::INNER_JOIN, $cond = '')
    {
        if ($cond != '')
        {
            $cond = " with {$cond} ";
        }
        $join_method = $type == Join::INNER_JOIN ? "innerJoin" : "leftJoin";
        $this->queryBuilder->$join_method($join_field, $alias, null, $cond);
        return $this;
    }

    /**
     * get total records
     *
     * @return integer
     */
    public function getTotalRecords()
    {
        $qb = clone $this->queryBuilder;
        $this->_addSearch($qb);
        $qb->resetDQLPart('orderBy');

        $gb = $qb->getDQLPart('groupBy');
        if (empty($gb) || !in_array($this->fields['_identifier_'], $gb))
        {
            $qb->select(" count({$this->fields['_identifier_']}) ");
            return $qb->getQuery()->getSingleScalarResult();
        }
        else
        {
            $qb->resetDQLPart('groupBy');
            $qb->select(" count(distinct {$this->fields['_identifier_']}) ");
            return $qb->getQuery()->getSingleScalarResult();
        }
    }

    /**
     * get data
     *
     * @param int $hydration_mode
     *
     * @return array
     */
    public function getData($hydration_mode)
    {
        $request    = $this->request;
        $dql_fields = array_values($this->fields);
        if ($request->get('iSortCol_0') != null)
        {
            $sortCol = $request->get('iSortCol_0') + ($this->hasMultiple() ? -1 : 0);

            //var_dump(current(explode(' as ', $dql_fields[$sortCol]))); die;
            $order_field = $sortCol < 0 ? null : explode(' as ', $dql_fields[$sortCol]);

            if(is_array($order_field))
                $order_field = end($order_field);
        }
        else
        {
            $order_field = null;
        }
        $qb = clone $this->queryBuilder;
        if (!is_null($order_field))
        {
            //var_dump($order_field); die;
            $qb->orderBy($order_field, $request->get('sSortDir_0', 'asc'));
        }
        else
        {
            $qb->resetDQLPart('orderBy');
        }
        if ($hydration_mode == Query::HYDRATE_ARRAY)
        {
            $selectFields = $this->fields;
            foreach ($selectFields as &$field)
            {
                if (!preg_match('~as~', $field))
                {
                    $field = $field . ' as ' . str_replace('.', '_', $field);
                }
            }
            $qb->select(implode(" , ", $selectFields));
        }
        else
        {
            $qb->select($this->entity_alias);
        }
        $this->_addSearch($qb);
        $query          = $qb->getQuery();
        $iDisplayLength = (int) $request->get('iDisplayLength');
        if ($iDisplayLength > 0)
        {
            $query->setMaxResults($iDisplayLength)->setFirstResult($request->get('iDisplayStart'));
        }
        //var_dump($query->getDQL()); die;
        $items                = $query->getResult($hydration_mode);
        $iTotalDisplayRecords = (string) count($items);
        $data                 = array();
        if ($hydration_mode == Query::HYDRATE_ARRAY)
        {
            foreach ($items as $item)
            {
                $data[] = array_values($item);
            }
        }
        else
        {
            foreach ($items as $item)
            {
                $_data = array();
                foreach ($this->fields as $field)
                {
                    $method  = "get" . ucfirst(substr($field, strpos($field, '.') + 1));
                    $_data[] = $item->$method();
                }
                $data[] = $_data;
            }
        }
        return $data;
    }

    /**
     * get entity name
     *
     * @return string
     */
    public function getEntityName()
    {
        return $this->entity_name;
    }

    /**
     * get entity alias
     *
     * @return string
     */
    public function getEntityAlias()
    {
        return $this->entity_alias;
    }

    /**
     * get fields
     *
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * get order field
     *
     * @return string
     */
    public function getOrderField()
    {
        return $this->order_field;
    }

    /**
     * get order type
     *
     * @return string
     */
    public function getOrderType()
    {
        return $this->order_type;
    }

    /**
     * get doctrine query builder
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getDoctrineQueryBuilder()
    {
        return $this->queryBuilder;
    }

    /**
     * set entity
     *
     * @param type $entity_name
     * @param type $entity_alias
     *
     * @return Datatable
     */
    public function setEntity($entity_name, $entity_alias)
    {
        $this->entity_name  = $entity_name;
        $this->entity_alias = $entity_alias;
        $this->queryBuilder->from($entity_name, $entity_alias);
        return $this;
    }

    /**
     * set fields
     *
     * @param array $fields
     *
     * @return Datatable
     */
    public function setFields(array $fields)
    {
        $this->fields = $fields;
        $this->queryBuilder->select(implode(', ', $fields));
        return $this;
    }

    /**
     * set order
     *
     * @param type $order_field
     * @param type $order_type
     *
     * @return Datatable
     */
    public function setOrder($order_field, $order_type)
    {
        $this->order_field = $order_field;
        $this->order_type  = $order_type;
        $this->queryBuilder->orderBy($order_field, $order_type);
        return $this;
    }

    /**
     * set fixed data
     *
     * @param type $data
     *
     * @return Datatable
     */
    public function setFixedData($data)
    {
        $this->fixed_data = $data;
        return $this;
    }

    /**
     * set query where
     *
     * @param string $where
     * @param array  $params
     *
     * @return Datatable
     */
    public function setWhere($where, array $params = array())
    {
        $this->queryBuilder->where($where);
        $this->queryBuilder->setParameters($params);
        return $this;
    }

    /**
     * set query group
     *
     * @param string $group
     *
     * @return Datatable
     */
    public function setGroupBy($group)
    {
        $this->queryBuilder->groupBy($group);
        return $this;
    }

    /**
     * set search
     *
     * @param string $search
     *
     * @return Datatable
     */
    public function setSearch($search)
    {
        $this->search = $search;
        return $this;
    }

    /**
     * set doctrine query builder
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     *
     * @return DoctrineBuilder
     */
    public function setDoctrineQueryBuilder(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        return $this;
    }

    public function hasMultiple()
    {
        return $this->hasMultiple;
    }

    public function setHasMultiple($val)
    {
        $this->hasMultiple = $val;
        return $this;
    }
}
