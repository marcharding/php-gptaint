# Call Scripts
# Rscript results_total_metrics.R

# Load necessary libraries
library(tidyverse)
library(RColorBrewer) # For color palettes

# Load data
data <- read.csv("csv/results_total_metrics.csv", sep = ";")

# Transform data from wide to long format
data_long <- data %>% pivot_longer(cols = -analyzer, names_to = "metric", values_to = "value")

# Set the file path for the PDF
pdf_file <- "pdf/results_total_metrics.pdf"

# Open a PDF device
pdf(pdf_file, width = 20, height = 10)

# Plot the data with enhanced aesthetics
ggplot(data_long, aes(x = analyzer, y = value, fill = metric)) +
  geom_bar(stat = "identity", position = "dodge") +
  scale_fill_brewer(palette = "Set3") + # Using a color palette
  theme_minimal(base_size = 15) + # Base size for text elements
  labs(title = "Performance Metrics by Analyzer", x = "Analyzer", y = "Value", fill = "Metric") +
  theme(
    axis.text.x = element_text(angle = 45, hjust = 1),
    plot.title = element_text(hjust = 0.5, size = 20, face = "bold"), # Center and bold graph title
    plot.margin = margin(10, 10, 20, 10), # Add padding around the plot
    axis.title = element_text(size = 16, face = "bold"), # Make axis titles larger and bold
    axis.text = element_text(size = 12), # Adjust axis text size
    legend.position = "top", # Place legend at top
    legend.title = element_text(size = 14, face = "bold"), # Make legend title bold
    legend.text = element_text(size = 12) # Ensure legend text is readable
  )

# Close the PDF device
dev.off()