# ACL Filter bridge for Doctrine QueryBuilder

This bundle lets your set up Doctrine queries that are joined against the ACL.
This helps close a gap in the Symfony's current ACL implementation. While
Symfony ACL allows you to efficiently check permissions for a specific object,
it has no efficient way to build list queries that only return objects for
which a user has been granted permission.

Under Symfony's ACL, the only way to list resources in this way is too loop
through them and test each one individually.  Pre-caching can improve the
performance somewhat, but if there are thousands of rows or more with only a
few valid results, this is far from ideal.  This system also doesn't lend
itself well to limit/offset queries.

This bundle gets around this by modifying queries built with QueryBuilder to
join the appropriate tables so that ACL permissions are checked during query
execution.  The result is a very efficient query that only returns the results
that you want.  Special query options, such as count, limit, or offset, will
all work normally.

The catch is that this bundle has to query the database directly, so
there may be compatibility issues or issues with future revisions of Doctrine.
It should be compatible with most databases, however, and I was able to
implement code from 4 years ago with very few changes to Symfony code only.
No code changes were made to the Doctrine-specific code, so this should
continue to work with Doctrine versions into the near future at least.

* Cloned from <a href="https://github.com/rejsmont/LabDB">rejsmont/LabDB</a>.
* Extracted just the src/VIB/SecurityBundle package
* Removed and changed some files that weren't relevant to the ACL filter system.
* Renamed to ACLFilterBundle, to reflect singular purpose.
* Updated to work with Symfony 3
* Added documentation

## Installation

### Composer Install

    composer require jaminv/aclfilter-bundle

or edit composer.json:

    # /composer.json
    "require": {
        ...
        "jaminv/aclfilter-bundle": "dev-master"
    },

### Register Bundle

    # /app/AppKernel.php
    class AppKernel extends Kernel
    {
        public function registerBundles()
        {
            $bundles = [
                ...
                new jaminv\ACLFilterBundle\jaminvACLFilterBundle(),

                new AppBundle\AppBundle(),
            ];
            ...

## Usage

### Basic Usage

Set up a basic query:

    $em = $this->getDoctrine->getManager();
    $builder = em->getRepository('AppBundle:SomeTable')->getQueryBuilder('a');
    $builder->select('a')
        ->where("a.somefield = 'value'");

Apply the AclFilter to the query:

    $aclfilter = $this->get('jaminv.aclfilter');
    $query = $aclfilter->apply($builder->getQuery(), array('EDIT'), $this->getUser(), 'a');
    $result = $query->getResult();

The AclFilter will modify the query so that it only returns results for objects
for which the current user has EDIT permissions.
