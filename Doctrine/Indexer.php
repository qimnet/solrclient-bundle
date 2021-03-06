<?php
namespace Qimnet\SolrClientBundle\Doctrine;
use Doctrine\ORM\EntityManager;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ORM\Query;
use Doctrine\DBAL\LockMode;
use Qimnet\SolrClientBundle\Annotation\Indexable;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 */
class Indexer {
    /**
     * @var \SolrClient $client
     */
    protected $client;
    /**
     * @var array $entities
     */
    protected $entities;
    /**
     * @var array $entity_fields
     */
    protected $entity_fields = array();
    /**
     * @var Reader $annotation_reader
     */
    protected $annotation_reader;
    
    /**
     * @var PropertyAccessorInterface $propertyAccessor
     */
    protected $propertyAccessor;


    public function __construct(\SolrClient $client, Reader $annotation_reader, PropertyAccessorInterface $propertyAccessor, array $entities) {
        $this->client = $client;
        $this->entities = $entities;
        $this->annotation_reader = $annotation_reader;
        $this->propertyAccessor = $propertyAccessor;
    }
    /**
     * Returns an object representing the indexable fields for the entity.
     * 
     * The following properties are defined in the returned object :
     *   - id : the id field
     *   - indexable : an array of indexable fields
     *   - needs_index: the needs_index field, if it exists
     *   - is_indexable: the is_indexable field if it exists
     *   
     * Each field is represented by an object containing the following 
     * properties :
     *   - virtual : true if the field is virtual
     *   - field : the name of the field in the Doctrine entity
     *   - method: the name of the getter method
     *   - solr_name : the name of the field
     *   - boost : the boost factor of the field
     *   - id : true if the field is the id field
     * 
     * @param string $entity_name the name of the entity
     * @return stdClass 
     */
    public function getEntityFields($entity_name)
    {
        if (!isset($this->entity_fields[$entity_name]))
        {
            $fields = new \stdClass;
            $fields->indexable = array();
            $fields->needs_index = null;
            $fields->is_indexable = null;
            $fields->id=null;
            $ref = new \ReflectionClass($entity_name);
            $get_object = function($name, $annotation) use ($entity_name)
            {
                $ret = new \stdClass;
                if ($annotation instanceof Indexable)
                {
                    $ret->solr_name = $annotation->solr_name;
                    $ret->boost = $annotation->boost;
                    $ret->id = $annotation->id;
                    $ret->virtual = $annotation->virtual;
                    if (!$ret->solr_name)
                    {
                        throw new \Exception("Solr field name required for $entity_name:$name");
                    }
                }
                return $ret;
            };
            $get_method_object = function($method, $annotation) use ($get_object)
            {
                $ret = $get_object($method, $annotation);
                $ret->method = $method;
                return $ret;
            };
            $get_field_object = function($field, $annotation) use ($get_object, $ref)
            {
                if (($annotation instanceof Indexable) && !$annotation->solr_name)
                {
                    $annotation->solr_name = $field;
                }
                $ret = $get_object($field, $annotation);
                $ret->field = $field;
                return $ret;
            };
            foreach($ref->getProperties() as $property)
            {
                try
                {
                    $ann = $this->annotation_reader
                            ->getPropertyAnnotation(
                                new \ReflectionProperty($entity_name, $property->name),
                                'Qimnet\SolrClientBundle\Annotation\Indexable');
                    if ($ann)
                    {
                        $fields->indexable[] = $get_field_object($property->name, $ann);
                    }
                    $ann = $this->annotation_reader
                            ->getPropertyAnnotation(
                                new \ReflectionProperty($entity_name, $property->name),
                                'Qimnet\SolrClientBundle\Annotation\NeedsIndex');
                    if ($ann)
                    {
                        $fields->needs_index = $get_field_object($property->name, $ann);
                    }
                    $ann = $this->annotation_reader
                            ->getPropertyAnnotation(
                                new \ReflectionProperty($entity_name, $property->name),
                                'Qimnet\SolrClientBundle\Annotation\IsIndexable');
                    if ($ann)
                    {
                        $fields->is_indexable = $get_field_object($property->name, $ann);
                    }
                }
                catch (\ReflectionException $ex) {}
            }
            foreach ($ref->getMethods() as  $method)
            {
                try
                {
                    $ann = $this->annotation_reader
                            ->getMethodAnnotation(
                                new \ReflectionMethod($entity_name, $method->name),
                                'Qimnet\SolrClientBundle\Annotation\Indexable');
                    if ($ann)
                    {
                        $fields->indexable[] = $get_method_object($method->name, $ann);
                    }
                    $ann = $this->annotation_reader
                            ->getMethodAnnotation(
                                new \ReflectionMethod($entity_name, $method->name),
                                'Qimnet\SolrClientBundle\Annotation\IsIndexable');
                    if ($ann)
                    {
                        $fields->is_indexable = $get_method_object($method->name, $ann);
                    }

                }
                catch (\ReflectionException $ex) {}
            }
            foreach ($fields->indexable as $field)
            {
                if ($field->id) $fields->id = $field;
            }
            $this->entity_fields[$entity_name] = $fields;
            if (count($fields->indexable) && !$fields->id)
            {
                throw new \Exception("Entity $entity_name has no Solr id.");
            }
        }
        return $this->entity_fields[$entity_name];
    }
    
    /**
     * Indexes all the entities for the repositories defined in the config 
     * file.
     * 
     * @param EntityManager $em
     * @param boolean $force set to true to index all entities
     */
    public function indexAllEntities(EntityManager $em, $force=false)
    {
        foreach($this->entities as $entity_name)
        {
            $this->indexEntities($em, $entity_name, $force);
        }
    }
    /**
     * Returns the value for a field object in an entity
     * 
     * @param object $entity
     * @param \stdClass $field
     * @return mixed
     */
    public function getFieldValue($entity, $field)
    {
        if (isset($field->method)) {
            return call_user_func(array($entity, $field->method));
        } else {
            return $this->propertyAccessor->getValue($entity, $field->field);
        }
    }
    
    /**
     * Sets the value for a field object in an entity
     * 
     * @param object $entity
     * @param \stdClass $field
     */
    public function setFieldValue($entity, $field, $value)
    {
        $this->propertyAccessor->setValue($entity, $field->field, $value);
    }

    /**
     * Creates a Solr document from the entity.
     * 
     * @param object $entity
     * @return \SolrInputDocument
     */
    public function createSolrDocument($entity)
    {
        $document = new \SolrInputDocument;
        $fields = $this->getEntityFields(get_class($entity));
        foreach ($fields->indexable as $field)
        {
            if (!$field->virtual)
            {
                $value = $this->getFieldValue($entity, $field);
                if (is_array($value) || ($value instanceof \Traversable))
                {
                    foreach($value as $subvalue)
                    {
                        $document->addField($field->solr_name, $subvalue, $field->boost);
                    }
                }
                else
                {
                    $document->addField($field->solr_name, $value, $field->boost);
                }
            }
        }
        return $document;
    }
    /**
     * Index entities for the given class. Updates the needs_index field if it
     * is present.
     * 
     * @param EntityManager $em
     * @param string $entity_name
     * @param boolean $force true to index all entities.
     */
    public function indexEntities(EntityManager $em, $entity_name, $force = false)
    {
        $class = $em->getRepository($entity_name)->getClassName();
        $fields = $this->getEntityFields($class);
        if (!$fields->needs_index && !$force)
        {
            return;
        }
        elseif ($force)
        {
            $query = $em->createQuery("SELECT t.id FROM $entity_name t");
        } 
        else
        {
            $query = $em->createQuery(
                "SELECT t.id FROM $entity_name t WHERE t.{$fields->needs_index->field} = 1");
        }
        
        $ids = array_map(function($row){ return $row['id']; },$query->getResult(Query::HYDRATE_ARRAY));
        foreach($ids as $id)
        {
            $em->beginTransaction();
            try {
                $entity = $em->find($entity_name, $id, 
                        $fields->needs_index ? LockMode::PESSIMISTIC_WRITE : LockMode::NONE);
                if ($entity && ($force || $this->getFieldValue($entity, $fields->needs_index)))
                {
                    $this->removeEntity($entity);
                    $this->indexEntity($entity);
                    if ($fields->needs_index)
                    {
                        $this->setFieldValue($entity, $fields->needs_index, 0);
                        $em->persist($entity);
                        $em->flush();
                    }
                }
                $em->commit();
            } catch (Exception $ex) {
                $em->rollback();
                $em->close();
                throw $ex;
            }
        }
            
    }
    /**
     * Returns the solr id of a given entity
     * 
     * @param object $entity
     * @return string
     */
    public function getSolrId($entity)
    {
        $class = get_class($entity);
        $fields = $this->getEntityFields($class);
        return $this->getFieldValue($entity, $fields->id);
    }
    /**
     * Removes the given entity from the solr index
     * 
     * @param object $entity
     */
    public function removeEntity($entity)
    {
        $this->client->deleteById($this->getSolrId($entity));
        $this->client->commit();
    }
    /**
     * Indexes the given entity
     * 
     * @param object $entity
     */
    public function indexEntity($entity)
    {
        $document = $this->createSolrDocument($entity);
        $this->client->addDocument($document);
        $this->client->commit();
        $this->fields = $this->getEntityFields(get_class($entity));
    }

    /**
     * Returns true if the entity is indexable.
     * 
     * @param object $entity
     * @return boolean
     */
    public function isIndexable($entity)
    {
        $fields = $this->getEntityFields(get_class($entity));
        return $fields->is_indexable
            ? $this->getFieldValue($entity, $fields->is_indexable)
            : $this->hasIndexableFields($entity);
    }
    /**
     * Returns true if the entity contains indexable fields.
     * 
     * @param object $entity
     * @return boolean
     */
    public function hasIndexableFields($entity)
    {
        return count($this->getEntityFields(get_class($entity))->indexable);
    }
    /**
     * Returns true if the indexation should be performed in realtime
     * 
     * @param object $entity
     * @return type
     */
    public function isRealtime($entity)
    {
        return is_null($this->getEntityFields(get_class($entity))->needs_index);
    }
}

?>
