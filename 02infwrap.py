"""
A Python wrapper for the Infection mutation testing framework CLI.
"""

import asyncio
import logging
import shlex
import subprocess
from typing import Any, Dict, List, NamedTuple, Optional, Set, Union

log = logging.getLogger(__name__)


# Structure to hold async results, similar to subprocess.CompletedProcess
class AsyncCompletedProcess(NamedTuple):
    returncode: int
    stdout: Optional[bytes]
    stderr: Optional[bytes]
    args: List[str]


class InfectionWrapper:
    """
    A Python wrapper for the Infection mutation testing framework CLI.
    """

    def __init__(self, infection_path: str = "infection"):
        """
        Initializes the InfectionWrapper.

        Args:
            infection_path (str): The path to the Infection executable or the
                                  command name if it's in the system's PATH.
                                  Defaults to 'infection'.
        """
        self.infection_path = infection_path
        self._options: Dict[str, str] = {}
        self._flags: Set[str] = set()
        self.logger = logging.getLogger(f"{__name__}.{self.__class__.__name__}")

    def _set_option(self, name: str, value: Optional[Union[str, int, float]]):
        """Helper method to set an option with a value."""
        if value is not None:
            self._options[name] = str(value)
            self.logger.debug("Set option %s=%s", name, value)
        elif name in self._options:
            del self._options[name]
            self.logger.debug("Unset option %s", name)

    def _set_flag(self, name: str, value: bool):
        """Helper method to set a boolean flag."""
        if value:
            self._flags.add(name)
            self.logger.debug("Set flag %s", name)
        elif name in self._flags:
            self._flags.remove(name)
            self.logger.debug("Unset flag %s", name)

    def threads(self, count: Optional[int]) -> "InfectionWrapper":
        """
        Sets the number of parallel Infection processes.
        Default: number of CPU cores.
        """
        self._set_option("--threads", count)
        return self

    def only_covered(self, enabled: bool = True) -> "InfectionWrapper":
        """Mutate only covered by tests lines of code."""
        self._set_flag("--only-covered", enabled)
        return self

    def show_mutations(self, enabled: bool = True) -> "InfectionWrapper":
        """Show escaped and not covered mutants."""
        self._set_flag("--show-mutations", enabled)
        return self

    def no_progress(self, enabled: bool = True) -> "InfectionWrapper":
        """Do not output progress bar."""
        self._set_flag("--no-progress", enabled)
        return self

    def force_progress(self, enabled: bool = True) -> "InfectionWrapper":
        """Force output progress bar."""
        self._set_flag("--force-progress", enabled)
        return self

    def log_verbosity(self, level: Optional[str]) -> "InfectionWrapper":
        """Set log verbosity level: 'all', 'default', 'none'."""
        allowed_levels = ["all", "default", "none"]
        if level is not None and level not in allowed_levels:
            # Log the error before raising
            self.logger.error(
                "Invalid log verbosity level '%s'. Allowed: %s",
                level,
                allowed_levels,
            )
            raise ValueError(
                f"Invalid log verbosity level. Allowed: {allowed_levels}"
            )
        self._set_option("--log-verbosity", level)
        return self

    def initial_tests_php_options(
        self, options: Optional[str]
    ) -> "InfectionWrapper":
        """PHP options passed to the PHP process running initial tests."""
        self._set_option("--initial-tests-php-options", options)
        return self

    def log_path(self, path: Optional[str]) -> "InfectionWrapper":
        """Path to the log file."""
        self._set_option("--log-path", path)
        return self

    def coverage_path(self, path: Optional[str]) -> "InfectionWrapper":
        """Path to existing coverage report (JUnit, Cobertura)."""
        self._set_option("--coverage", path)
        return self

    def mutators(
        self, mutators_list: Optional[Union[str, List[str]]]
    ) -> "InfectionWrapper":
        """Specify particular mutators, comma-separated or as a list."""
        value = None
        if isinstance(mutators_list, list):
            value = ",".join(mutators_list)
        elif isinstance(mutators_list, str):
            value = mutators_list
        self._set_option("--mutators", value)
        return self

    def ignore_msi_with_no_mutations(
        self, enabled: bool = True
    ) -> "InfectionWrapper":
        """Ignore MSI calculation when no mutations are generated."""
        self._set_flag("--ignore-msi-with-no-mutations", enabled)
        return self

    def min_msi(self, percentage: Optional[float]) -> "InfectionWrapper":
        """Minimum Mutation Score Indicator (MSI) percentage required."""
        self._set_option("--min-msi", percentage)
        return self

    def min_covered_msi(
        self, percentage: Optional[float]
    ) -> "InfectionWrapper":
        """Minimum Covered MSI percentage required."""
        self._set_option("--min-covered-msi", percentage)
        return self

    def test_framework(self, framework: Optional[str]) -> "InfectionWrapper":
        """Name of the testing framework (e.g., phpunit, phpspec)."""
        self._set_option("--test-framework", framework)
        return self

    def test_framework_options(
        self, options: Optional[str]
    ) -> "InfectionWrapper":
        """Options to be passed to the test framework."""
        self._set_option("--test-framework-options", options)
        return self

    def filter(self, filter_string: Optional[str]) -> "InfectionWrapper":
        """Filter files to be mutated (e.g., 'src/OnlyThisFile.php')."""
        self._set_option("--filter", filter_string)
        return self

    def formatter(self, name: Optional[str]) -> "InfectionWrapper":
        """Output formatter name ('dot', 'progress')."""
        allowed_formatters = ["dot", "progress"]
        if name is not None and name not in allowed_formatters:
            self.logger.error(
                "Invalid formatter '%s'. Allowed: %s", name, allowed_formatters
            )
            raise ValueError(
                f"Invalid formatter. Allowed: {allowed_formatters}"
            )
        self._set_option("--formatter", name)
        return self

    def configuration(self, path: Optional[str]) -> "InfectionWrapper":
        """
        Path to the configuration file (infection.json[5], infection.json.dist).
        """
        self._set_option("--configuration", path)
        return self

    def git_diff_filter(self, filter_type: Optional[str]) -> "InfectionWrapper":
        """
        Mutate only changed files based on git diff (e.g., 'HEAD', 'master').
        """
        self._set_option("--git-diff-filter", filter_type)
        return self

    def git_diff_lines(self, enabled: bool = True) -> "InfectionWrapper":
        """
        Mutate only changed lines based on git diff. Requires --git-diff-filter.
        """
        self._set_flag("--git-diff-lines", enabled)
        return self

    def logger_github(self, enabled: bool = True) -> "InfectionWrapper":
        """
        Log results in GitHub Annotations format.
        Requires running in GitHub Actions.
        """
        self._set_flag("--logger-github", enabled)
        return self

    def logger_html(self, path: Optional[str]) -> "InfectionWrapper":
        """Generate HTML report to the specified path."""
        self._set_option("--logger-html", path)
        return self

    def logger_summary_file(self, path: Optional[str]) -> "InfectionWrapper":
        """Generate a summary file to the specified path."""
        self._set_option("--logger-summary-file", path)
        return self

    def logger_text_file(self, path: Optional[str]) -> "InfectionWrapper":
        """Generate a text log file to the specified path."""
        self._set_option("--logger-text-file", path)
        return self

    def logger_per_mutator(self, path: Optional[str]) -> "InfectionWrapper":
        """Generate a per-mutator report file to the specified path."""
        self._set_option("--logger-per-mutator", path)
        return self

    def logger_debug_file(self, path: Optional[str]) -> "InfectionWrapper":
        """Generate a debug log file to the specified path."""
        self._set_option("--logger-debug-file", path)
        return self

    def existing_coverage_path(self, path: Optional[str]) -> "InfectionWrapper":
        """Path to the existing coverage directory."""
        self._set_option("--existing-coverage-path", path)
        return self

    def tmp_dir(self, path: Optional[str]) -> "InfectionWrapper":
        """Path to the temporary directory used by Infection."""
        self._set_option("--tmp-dir", path)
        return self

    def skip_initial_tests(self, enabled: bool = True) -> "InfectionWrapper":
        """Skip the initial test run."""
        self._set_flag("--skip-initial-tests", enabled)
        return self

    def skip_coverage(self, enabled: bool = True) -> "InfectionWrapper":
        """Skip coverage generation."""
        self._set_flag("--skip-coverage", enabled)
        return self

    def debug(self, enabled: bool = True) -> "InfectionWrapper":
        """Enable debug mode with maximum verbosity."""
        self._set_flag("--debug", enabled)
        return self

    def dry_run(self, enabled: bool = True) -> "InfectionWrapper":
        """Run Infection without actually mutating code or running tests."""
        self._set_flag("--dry-run", enabled)
        return self

    def no_interaction(self, enabled: bool = True) -> "InfectionWrapper":
        """Do not ask any interactive questions."""
        self._set_flag("-n", enabled)  # Use short flag as per docs often used
        return self

    def verbose(self, level: Optional[int]) -> "InfectionWrapper":
        """
        Increase verbosity.
        -v for normal, -vv for more verbose, -vvv for debug.
        """
        self.logger.debug("Setting verbosity level to %s", level)
        # Remove existing verbosity flags first
        self._flags.discard("-v")
        self._flags.discard("-vv")
        self._flags.discard("-vvv")
        self._flags.discard("--verbose")

        if level == 1:
            self._flags.add("-v")
        elif level == 2:
            self._flags.add("-vv")
        elif level == 3:
            self._flags.add("-vvv")
        elif level is not None and level > 3:
            self._flags.add("-vvv")
        return self

    def build_command(self) -> List[str]:
        """Builds the command list based on configured options and flags."""
        command = [self.infection_path]
        for option, value in self._options.items():
            command.append(f"{option}={shlex.quote(value)}")
        for flag in self._flags:
            command.append(flag)
        self.logger.debug("Built command: %s", command)
        return command

    def run(
        self,
        capture_output: bool = True,
        text: bool = True,
        check: bool = False,
        **kwargs: Any,
    ) -> subprocess.CompletedProcess[Any]:
        """
        Executes the Infection command synchronously with the options.
        Uses logging for status and error messages.
        """
        command_list = self.build_command()
        self.logger.info("Running command (sync): %s", " ".join(command_list))

        try:
            proc_result = subprocess.run(
                command_list,
                capture_output=capture_output,
                text=text,
                # Let subprocess handle raising CalledProcessError if check=True
                check=check,
                **kwargs,
            )
            self.logger.info(
                "Sync command finished with return code %d",
                proc_result.returncode,
            )
            if proc_result.stdout:
                self.logger.debug(
                    "Sync command stdout:\n%s", proc_result.stdout
                )
            if proc_result.stderr:
                self.logger.debug(
                    "Sync command stderr:\n%s", proc_result.stderr
                )  # Use debug for stderr unless error
            return proc_result
        except FileNotFoundError:
            self.logger.error(
                "Infection executable not found at '%s'.",
                self.infection_path,
            )
            raise  # Re-raise the exception
        except subprocess.CalledProcessError as e:
            # This block is reached only if check=True and the process fails
            self.logger.error(
                "Infection command failed with exit code %d.", e.returncode
            )
            # Log output captured by the exception
            stdout_log = e.stdout.strip() if e.stdout else "N/A"
            stderr_log = e.stderr.strip() if e.stderr else "N/A"
            self.logger.error("Command stdout:\n%s", stdout_log)
            self.logger.error("Command stderr:\n%s", stderr_log)
            raise  # Re-raise the error after logging
        except OSError as os_error:
            self.logger.exception(
                "OS error while running Infection: %s", os_error
            )
            raise
        except ValueError as val_error:
            self.logger.exception(
                "Value error while running Infection: %s", val_error
            )
            raise

    async def run_async(
        self, check: bool = False, **kwargs: Any
    ) -> AsyncCompletedProcess:
        """
        Executes the Infection command asynchronously with the options.
        Uses logging for status and error messages.
        """
        command_list = self.build_command()
        self.logger.info("Running command (async): %s", " ".join(command_list))

        try:
            process = await asyncio.create_subprocess_exec(
                *command_list,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                **kwargs,
            )

            stdout_bytes, stderr_bytes = await process.communicate()
            self.logger.info(
                "Async command finished with return code %d",
                process.returncode or 0,
            )

            async_result = AsyncCompletedProcess(
                returncode=process.returncode or 0,  # Default to 0 if None
                stdout=stdout_bytes,
                stderr=stderr_bytes,
                args=command_list,
            )

            if async_result.stdout:
                self.logger.debug(
                    "Async command stdout:\n%s",
                    async_result.stdout.decode(errors="replace"),
                )
            if async_result.stderr:
                self.logger.debug(
                    "Async command stderr:\n%s",
                    async_result.stderr.decode(errors="replace"),
                )  # Use debug unless error

            if check and async_result.returncode != 0:
                self.logger.error(
                    "Infection async command failed with exit code %d.",
                    async_result.returncode,
                )
                # Log output before raising
                stdout_log = (
                    async_result.stdout.decode(errors="replace").strip()
                    if async_result.stdout
                    else "N/A"
                )
                stderr_log = (
                    async_result.stderr.decode(errors="replace").strip()
                    if async_result.stderr
                    else "N/A"
                )
                self.logger.error("Command stdout:\n%s", stdout_log)
                self.logger.error("Command stderr:\n%s", stderr_log)
                raise subprocess.CalledProcessError(
                    async_result.returncode,
                    async_result.args,
                    output=async_result.stdout,
                    stderr=async_result.stderr,
                )

            return async_result

        except FileNotFoundError:
            self.logger.error(
                "Infection executable not found at '%s'.",
                self.infection_path,
            )
            raise
        # pylint: disable=try-except-raise
        except subprocess.CalledProcessError:
            # This block catches the manually raised error when check=True
            # Logging is already done before raising, so just re-raise
            raise
        except OSError as os_error:
            self.logger.exception(
                "OS error while running Infection asynchronously: %s", os_error
            )
            raise
        except ValueError as val_error:
            self.logger.exception(
                "Value error while running Infection asynchronously: %s",
                val_error,
            )
            raise
