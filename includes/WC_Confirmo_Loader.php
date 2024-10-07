<?php

/**
 * Helper class to register WP hooks (actions, filters) from plugin
 */
class WC_Confirmo_Loader
{

	protected array $actions;

	protected array $filters;

	public function __construct()
    {
		$this->actions = [];
		$this->filters = [];
	}

    /**
     * Go through registered actions and filters and register them in WP
     *
     * @return void
     */
    public function run(): void
    {
		foreach ($this->filters as $hook) {
			add_filter($hook['hook'], $hook['callback'], $hook['priority'], $hook['accepted_args']);
		}

		foreach ($this->actions as $hook) {
			add_action($hook['hook'], $hook['callback'], $hook['priority'], $hook['accepted_args']);
		}
	}

    /**
     * Add action to be registered by helper
     *
     * @param string $hook
     * @param callable $callback
     * @param int $priority
     * @param int $accepted_args
     * @return void
     */
    public function addAction(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
		$this->actions = $this->add($this->actions, $hook, $callback, $priority, $accepted_args);
	}

    /**
     * Add filter to be registered by helper
     *
     * @param string $hook
     * @param callable $callback
     * @param int $priority
     * @param int $accepted_args
     * @return void
     */
    public function addFilter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
		$this->filters = $this->add($this->filters, $hook, $callback, $priority, $accepted_args);
	}

    private function add(array $hooks, string $hook, callable $callback, int $priority, int $accepted_args): array
    {
		$hooks[] = [
			'hook' => $hook,
			'callback' => $callback,
			'priority' => $priority,
			'accepted_args' => $accepted_args
        ];

		return $hooks;
	}

}
