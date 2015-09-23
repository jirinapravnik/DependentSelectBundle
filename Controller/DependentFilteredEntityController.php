<?php

namespace Shtumi\UsefulBundle\Controller;

use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use Symfony\Component\HttpFoundation\Response;

class DependentFilteredEntityController extends Controller
{
    const DQL_PARAMETER_PREFIX = 'param_';

    public function getOptionsAction()
    {
        $em = $this->get('doctrine')->getManager();
        $request = $this->getRequest();
        $translator = $this->get('translator');

        $entity_alias = $request->get('entity_alias');
        $parent_id    = $request->get('parent_id');
        $fallbackParentId = $request->get('fallback_parent_id');
        $empty_value  = $request->get('empty_value');

        $excludedEntityId = $request->get('excluded_entity_id');

        $entities = $this->get('service_container')->getParameter('shtumi.dependent_filtered_entities');
        $entity_inf = $entities[$entity_alias];

        //set the fallback
        if ($entity_inf['fallback_alias'] !== null && !empty($fallbackParentId) && empty($parent_id)) {
            $parent_id = $fallbackParentId;
            $entity_inf = $entities[$entity_inf['fallback_alias']];
        }

        if ($entity_inf['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY'){
            if (false === $this->get('security.context')->isGranted( $entity_inf['role'] )) {
                throw new AccessDeniedException();
            }
        }

        /** @var QueryBuilder $qb */
        $qb = $this->getDoctrine()
            ->getRepository($entity_inf['class'])
            ->createQueryBuilder('e');

        if($entity_inf['grandparent_property']) {
            $qb
                ->leftJoin("e." . $entity_inf['parent_property'], "parent")
                ->where("parent." . $entity_inf['grandparent_property'] . ' = :parent_id');
        } else {
            $qb
                ->where('e.' . $entity_inf['parent_property'] . ' = :parent_id');
        }

        $qb
            ->andWhere('e.id != :excluded_entity_id');


        //add the filters to a query
        foreach ($entity_inf['child_entity_filters'] as $key => $filter) {
            $parameterName = DependentFilteredEntityController::DQL_PARAMETER_PREFIX . $filter['property'] . $key;

            $qb
                ->andWhere('e.' . $filter['property'] . ' ' . $filter['sign'] . ' :' . $parameterName)
                ->setParameter($parameterName, $filter['value']);
        }

       $qb->orderBy('e.' . $entity_inf['order_property'], $entity_inf['order_direction'])
            ->setParameter('parent_id', $parent_id)
            ->setParameter('excluded_entity_id', $excludedEntityId);


        if (null !== $entity_inf['callback']) {
            $repository = $qb->getEntityManager()->getRepository($entity_inf['class']);

            if (!method_exists($repository, $entity_inf['callback'])) {
                throw new \InvalidArgumentException(sprintf('Callback function "%s" in Repository "%s" does not exist.', $entity_inf['callback'], get_class($repository)));
            }

            $repository->$entity_inf['callback']($qb);
        }

        $results = $qb->getQuery()->getResult();

        if (empty($results)) {
            return new Response('<option value="">' . $translator->trans($entity_inf['no_result_msg']) . '</option>');
        }

        $html = '';
        if ($empty_value !== false)
            $html .= '<option value="">' . $translator->trans($empty_value) . '</option>';

        $getter =  $this->getGetterName($entity_inf['property']);

        foreach($results as $result)
        {
            if ($entity_inf['property'])
                $res = $result->$getter();
            else $res = (string)$result;

            $html = $html . sprintf("<option value=\"%d\">%s</option>",$result->getId(), $res);
        }

        return new Response($html);

    }


    public function getJSONAction()
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');
        $request = $this->get('request');

        $entity_alias = $request->get('entity_alias');
        $parent_id    = $request->get('parent_id');

        $entities = $this->get('service_container')->getParameter('shtumi.dependent_filtered_entities');
        $entity_inf = $entities[$entity_alias];

        if ($entity_inf['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY'){
            if (false === $this->get('security.context')->isGranted( $entity_inf['role'] )) {
                throw new AccessDeniedException();
            }
        }

        $term = $request->get('term');
        $maxRows = $request->get('maxRows', 20);

        $like = '%' . $term . '%';

        $property = $entity_inf['property'];
        if (!$entity_inf['property_complicated']) {
            $property = 'e.' . $property;
        }

        $qb = $em->createQueryBuilder()
            ->select('e')
            ->from($entity_inf['class'], 'e')
            ->where('e.' . $entity_inf['parent_property'] . ' = :parent_id')
            ->setParameter('parent_id', $parent_id)
            ->orderBy('e.' . $entity_inf['order_property'], $entity_inf['order_direction'])
            ->setParameter('like', $like )
            ->setMaxResults($maxRows);

        if ($entity_inf['case_insensitive']) {
            $qb->andWhere('LOWER(' . $property . ') LIKE LOWER(:like)');
        } else {
            $qb->andWhere($property . ' LIKE :like');
        }

        $results = $qb->getQuery()->getResult();

        $res = array();
        foreach ($results AS $r){
            $res[] = array(
                'id' => $r->getId(),
                'text' => (string)$r
            );
        }

        return new Response(json_encode($res));
    }

    private function getGetterName($property)
    {
        $name = "get";
        $name .= mb_strtoupper($property[0]) . substr($property, 1);

        while (($pos = strpos($name, '_')) !== false){
            $name = substr($name, 0, $pos) . mb_strtoupper(substr($name, $pos+1, 1)) . substr($name, $pos+2);
        }

        return $name;

    }
}
