<?php
namespace Upscale\Stdlib\Object\Lifecycle;

use Exception as Stack;

class Watcher
{
    /**
     * @var string
     */
    private $probeName;

    /**
     * @var Probe[] 
     */
    private $probes = [];

    /**
     * @var int
     */
    private $probeZeroRefCount = 0;

    /**
     * Inject dependencies
     *
     * @param string $probeName
     */
    public function __construct($probeName = '---probe')
    {
        $this->probeName = $probeName;
    }

    /**
     * Count references to a given variable
     *
     * @param mixed $variable
     * @return int
     * @throws \UnexpectedValueException
     */
    protected function countReferences(&$variable)
    {
        ob_start();
        debug_zval_dump($variable);
        $dump = ob_get_clean();
        if (preg_match('/refcount\((\d+)\)/', $dump, $matches)) {
            return (int)$matches[1];
        }
        throw new \UnexpectedValueException('Unable to count references.');
    }

    /**
     * Start watching health of one or more objects
     *
     * @param object|object[] $objects
     */
    public function watch($objects)
    {
        $stack = new Stack();
        $objects = is_array($objects) ? $objects : [$objects];
        foreach ($objects as $object) {
            $this->attachProbe($object, $stack);
        }
    }

    /**
     * Stop watching health of one or more objects
     *
     * @param object|object[] $objects
     */
    public function unwatch($objects)
    {
        $objects = is_array($objects) ? $objects : [$objects];
        foreach ($objects as $object) {
            $this->detachProbe($object);
        }
    }

    /**
     * Reference a probe from a given object incrementing the ref count of the probe
     * 
     * @param object $object
     * @param Stack $stack
     */
    protected function attachProbe($object, Stack $stack)
    {
        if (!property_exists($object, $this->probeName)) {
            $probe = new Probe($object, $stack);
            $this->probes[$probe->getId()] = $probe;
            if (!$this->probeZeroRefCount) {
                $this->probeZeroRefCount = $this->countReferences($probe);
            }
            $object->{$this->probeName} = $probe;
        }
    }

    /**
     * Remove reference to a probe from a given object decrementing the ref count of the probe
     *
     * @param object $object
     */
    public function detachProbe($object)
    {
        if (property_exists($object, $this->probeName)) {
            $probe = $object->{$this->probeName};
            if ($probe instanceof Probe) {
                unset($this->probes[$probe->getId()]);
                unset($object->{$this->probeName});
            }
        }
    }

    /**
     * Detect objects survived since starting their health watch
     *
     * @param bool $accurate
     * @return array
     * @throws \UnexpectedValueException
     */
    public function detectAliveObjects($accurate = true)
    {
        if ($accurate) {
            $this->collectGarbage();
        }
        $probes = $this->filterProbes($this->probes, true);
        return $this->renderReport($probes);
    }

    /**
     * Detect objects destroyed since starting their health watch
     *
     * @param bool $accurate
     * @return array
     * @throws \UnexpectedValueException
     */
    public function detectGoneObjects($accurate = true)
    {
        if ($accurate) {
            $this->collectGarbage();
        }
        $probes = $this->filterProbes($this->probes, false);
        return $this->renderReport($probes);
    }

    /**
     * Free resources allocated for tracking objects that are no longer alive.
     * Information on destroyed objects will be no longer be available. 
     */
    public function flush()
    {
        $this->collectGarbage();
        $this->probes = $this->filterProbes($this->probes, true);
    }

    /**
     * Destroy objects awaiting garbage collection of circular references
     */
    protected function collectGarbage()
    {
        gc_collect_cycles();
    }

    /**
     * Filter probes by health of objects they are tracking 
     * 
     * @param Probe[] $probes
     * @param bool $alive
     * @return Probe[]
     */
    protected function filterProbes(array $probes, $alive)
    {
        $result = [];
        foreach ($probes as $probeId => $probe) {
            $health = ($this->countReferences($probe) > $this->probeZeroRefCount);
            if ($health === $alive) {
                $result[$probeId] = $probe;
            }
        }
        return $result;
    }

    /**
     * Render report on given probes
     *
     * @param Probe[] $probes
     * @return array
     */
    protected function renderReport(array $probes)
    {
        $result = [];
        foreach ($probes as $probe) {
            $result[] = [
                'type'  => $probe->getOwnerType(),
                'hash'  => $probe->getOwnerHash(),
                'trace' => $probe->getStackTrace(),
            ];
        }
        return $result;
    }
}
