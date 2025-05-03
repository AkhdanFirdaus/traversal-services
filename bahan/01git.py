"""
TODO: move to a proper module and structure
"""

import asyncio
import tempfile
from concurrent.futures import ThreadPoolExecutor
from typing import Any

from git import Repo


async def clone_async(
    url: str,
    to_path: str,
    *args: Any,
) -> Repo:
    with ThreadPoolExecutor() as executor:
        loop = asyncio.get_event_loop()
        # TODO: use branch
        # TODO: do shallow clone
        repo = await loop.run_in_executor(
            executor,
            Repo.clone_from,
            url,
            to_path,
            *args,
        )
        return repo


# below is just a test
async def main() -> None:
    repo_url = "https://github.com/danepowell/mutation-example.git"
    with tempfile.TemporaryDirectory(prefix="mutagen-prj-") as temp_dir:
        repo = await clone_async(repo_url, temp_dir)
        tree = repo.head.commit.tree
        contents = [(entry, entry.name, entry.type) for entry in tree]
        print(f"Contents of {repo_url} in {temp_dir}:")
        for entry, name, entry_type in contents:
            print(f"{entry} {name} {entry_type}")


if __name__ == "__main__":
    asyncio.run(main())
