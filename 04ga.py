import random
import subprocess
import os
from deap import base, creator, tools

# === PHP Test Generation ===

def generate_php_test_case(a, b, method="divide"):
    expected = "exception" if method == "divide" and b == 0 else (a / b)
    method_name = f"test_{method}_{a}_{b}".replace("-", "minus")
    code = f"""
    public function {method_name}() {{
        $calc = new Calculator();
    """
    if method == "divide" and b == 0:
        code += "        $this->expectException(InvalidArgumentException::class);\n"
    else:
        code += f"        $result = $calc->{method}({a}, {b});\n"
        code += f"        $this->assertEquals({expected}, $result);\n"
    code += "    }\n"
    return code

def wrap_in_php_test_class(test_methods):
    return f"""<?php
use PHPUnit\\Framework\\TestCase;

class CalculatorTest extends TestCase {{
{''.join(test_methods)}
}}
"""

def write_php_test_file(content, filename="tests/CalculatorTest.php"):
    os.makedirs("tests", exist_ok=True)
    with open(filename, "w") as f:
        f.write(content)

# === Mutation Testing Evaluation ===

def evaluate_infection_score():
    try:
        result = subprocess.run(
            ["vendor/bin/infection", "--min-msi=0", "--threads=1", "--test-framework=phpunit", "--no-progress"],
            capture_output=True, text=True, cwd="."
        )
        for line in result.stdout.splitlines():
            if "Mutation Score Indicator" in line:
                msi = float(line.split(":")[1].strip().replace("%", ""))
                return msi,
    except Exception as e:
        print("Infection run failed:", e)
    return 0.0,

# === Genetic Algorithm Setup ===

creator.create("FitnessMax", base.Fitness, weights=(1.0,))
creator.create("Individual", list, fitness=creator.FitnessMax)

toolbox = base.Toolbox()
toolbox.register("a", random.randint, -100, 100)
toolbox.register("b", random.randint, -100, 100)
toolbox.register("individual", tools.initCycle, creator.Individual, (toolbox.a, toolbox.b), n=1)
toolbox.register("population", tools.initRepeat, list, toolbox.individual)

def evaluate(individual):
    a, b = individual
    test = generate_php_test_case(a, b)
    class_code = wrap_in_php_test_class([test])
    write_php_test_file(class_code)
    return evaluate_infection_score()

toolbox.register("evaluate", evaluate)
toolbox.register("mate", tools.cxBlend, alpha=0.5)
toolbox.register("mutate", tools.mutGaussian, mu=0, sigma=20, indpb=0.5)
toolbox.register("select", tools.selTournament, tournsize=3)

# === GA Main Loop ===

def main():
    pop = toolbox.population(n=10)
    NGEN = 5
    for gen in range(NGEN):
        print(f"\n📈 Generation {gen}")
        fitnesses = list(map(toolbox.evaluate, pop))
        for ind, fit in zip(pop, fitnesses):
            ind.fitness.values = fit
            print(f"Test({ind[0]}, {ind[1]}) => MSI: {fit[0]}")

        offspring = toolbox.select(pop, len(pop))
        offspring = list(map(toolbox.clone, offspring))

        # Apply crossover and mutation
        for child1, child2 in zip(offspring[::2], offspring[1::2]):
            if random.random() < 0.5:
                toolbox.mate(child1, child2)
                del child1.fitness.values
                del child2.fitness.values

        for mutant in offspring:
            if random.random() < 0.2:
                toolbox.mutate(mutant)
                del mutant.fitness.values

        # Re-evaluate only new individuals
        invalid_ind = [ind for ind in offspring if not ind.fitness.valid]
        fitnesses = map(toolbox.evaluate, invalid_ind)
        for ind, fit in zip(invalid_ind, fitnesses):
            ind.fitness.values = fit

        pop[:] = offspring

    best = tools.selBest(pop, 1)[0]
    print(f"\n🏆 Best individual: {best}, MSI: {best.fitness.values[0]}")

if __name__ == "__main__":
    main()
